CREATE ROLE kampy_native_roster_owner NOLOGIN NOINHERIT;
CREATE ROLE kampy_native_roster_reader NOLOGIN NOINHERIT;
CREATE TABLE public.kampy_roster_approved_events (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  source_namespace text NOT NULL CHECK (source_namespace ~ '^[a-z0-9][a-z0-9._:-]{1,159}$'),
  account_id bigint NOT NULL REFERENCES public.accounts(id),
  event_id bigint NOT NULL REFERENCES public.events(id),
  source_reference text NOT NULL CHECK (source_reference ~ '^approval:[A-Za-z0-9._:-]+$'),
  retired_at timestamptz,
  UNIQUE (source_namespace,account_id,event_id)
);
GRANT USAGE ON SCHEMA public TO kampy_native_roster_owner,kampy_native_roster_reader;
-- PostgreSQL requires the destination owner to have schema CREATE during
-- ownership transfer; the Laravel migration transaction contains this grant.
GRANT CREATE ON SCHEMA public TO kampy_native_roster_owner;
GRANT SELECT ON public.kampy_roster_approved_events TO kampy_native_roster_owner;
GRANT SELECT (id,account_id,deleted_at) ON public.events TO kampy_native_roster_owner;
GRANT SELECT (id,event_id,order_id,product_id,public_id,first_name,last_name,email,status,created_at,updated_at,deleted_at) ON public.attendees TO kampy_native_roster_owner;
GRANT SELECT (id,event_id,first_name,last_name,email,status,payment_status,refund_status,created_at,updated_at,deleted_at) ON public.orders TO kampy_native_roster_owner;
GRANT SELECT (id,event_id,title) ON public.products TO kampy_native_roster_owner;
GRANT SELECT (attendee_id,event_id,created_at,deleted_at) ON public.attendee_check_ins TO kampy_native_roster_owner;
GRANT SELECT (event_id,order_id,attendee_id,attendee_public_id,assignment_id,assignment_revision,respondent_id,status,recovery_evidence_id,replaced_assignment_id,link_replacement_requested_at) ON public.gvsu_registration_assignments TO kampy_native_roster_owner;
GRANT SELECT (id,cohort_id,order_id,event_id,purged_at) ON public.order_receipt_recovery_evidence TO kampy_native_roster_owner;
GRANT SELECT (id,event_id,account_id,sealed_at,revoked_at,valid_until) ON public.historical_receipt_recovery_cohorts TO kampy_native_roster_owner;

CREATE FUNCTION public.kampy_roster_event_allowed_v1(p_namespace text,p_account bigint,p_event bigint) RETURNS boolean
LANGUAGE sql STABLE SECURITY DEFINER SET search_path=pg_catalog AS $$
 SELECT EXISTS(SELECT 1 FROM public.kampy_roster_approved_events a
 JOIN public.events e ON e.id=a.event_id AND e.account_id=a.account_id AND e.deleted_at IS NULL
 WHERE a.source_namespace=p_namespace AND a.account_id=p_account AND a.event_id=p_event AND a.retired_at IS NULL)
$$;
ALTER FUNCTION public.kampy_roster_event_allowed_v1(text,bigint,bigint) OWNER TO kampy_native_roster_owner;
REVOKE ALL ON FUNCTION public.kampy_roster_event_allowed_v1(text,bigint,bigint) FROM PUBLIC;

CREATE FUNCTION public.kampy_roster_event_snapshot_v1(p_namespace text,p_account bigint,p_event bigint,p_max_rows integer) RETURNS jsonb
LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path=pg_catalog AS $$
DECLARE result jsonb;
BEGIN
 IF NOT public.kampy_roster_event_allowed_v1(p_namespace,p_account,p_event) THEN
   RAISE EXCEPTION 'roster source unavailable' USING ERRCODE='42501';
 END IF;
 IF p_max_rows IS NULL OR p_max_rows<1 OR p_max_rows>10001 THEN
   RAISE EXCEPTION 'invalid roster bound' USING ERRCODE='22023';
 END IF;
 IF EXISTS(SELECT 1 FROM public.attendees a LEFT JOIN public.orders o ON o.id=a.order_id
   WHERE a.deleted_at IS NULL AND (a.event_id=p_event OR o.event_id=p_event)
   AND (o.id IS NULL OR a.event_id<>o.event_id)) THEN
   RAISE EXCEPTION 'inconsistent roster source';
 END IF;
 SELECT COALESCE(jsonb_agg(to_jsonb(r)),'[]'::jsonb) INTO result FROM (
   SELECT a.id::text AS "attendeeId",a.event_id::text AS "eventId",a.order_id::text AS "orderId",
     a.public_id AS "ticketId", a.first_name AS "firstName",a.last_name AS "lastName",
     a.status AS "ticketStatus",p.title AS "productLabel",o.status AS "orderStatus",
     o.payment_status AS "paymentStatus",o.refund_status AS "refundStatus",
     a.created_at AS "registeredAt",o.created_at AS "orderCreatedAt",
     a.updated_at AS "attendeeUpdatedAt",o.updated_at AS "orderUpdatedAt",
     g.attendee_id IS NOT NULL AS "assignmentExists",binding.valid AS "assignmentBindingValid",
     CASE WHEN binding.valid THEN g.assignment_id END AS "assignmentId",
     CASE WHEN binding.valid THEN g.assignment_revision END AS "assignmentRevision",
     CASE WHEN binding.valid THEN g.respondent_id END AS "respondentId",
     CASE WHEN binding.valid THEN g.status END AS "assignmentStatus",
     binding.valid AND (g.recovery_evidence_id IS NULL OR (
       g.replaced_assignment_id IS NULL AND g.link_replacement_requested_at IS NULL AND EXISTS(
         SELECT 1 FROM public.order_receipt_recovery_evidence e
         JOIN public.historical_receipt_recovery_cohorts h ON h.id=e.cohort_id
         WHERE e.id=g.recovery_evidence_id AND e.order_id=a.order_id AND e.event_id=a.event_id
           AND h.event_id=a.event_id AND h.account_id=p_account
           AND h.sealed_at IS NOT NULL AND h.revoked_at IS NULL
           AND h.valid_until>statement_timestamp() AND e.purged_at IS NULL
       ))) AS "assignmentAuthorityEligible",
     ci.checked_in_at AS "firstCheckedInAt"
   FROM public.attendees a JOIN public.orders o ON o.id=a.order_id AND o.event_id=a.event_id
   LEFT JOIN public.products p ON p.id=a.product_id AND p.event_id=a.event_id
   LEFT JOIN LATERAL (
     SELECT ga.event_id,ga.order_id,ga.attendee_id,ga.attendee_public_id,ga.assignment_id,
       ga.assignment_revision,ga.respondent_id,ga.status,ga.recovery_evidence_id,
       ga.replaced_assignment_id,ga.link_replacement_requested_at,count(*) OVER () AS assignment_count
     FROM public.gvsu_registration_assignments ga WHERE ga.attendee_id=a.id
     ORDER BY ga.assignment_id LIMIT 1
   ) g ON true
   CROSS JOIN LATERAL (SELECT COALESCE(g.assignment_count=1 AND g.event_id=a.event_id
     AND g.order_id=a.order_id AND g.attendee_public_id=a.public_id,false) AS valid) binding
   LEFT JOIN LATERAL (SELECT min(c.created_at) AS checked_in_at FROM public.attendee_check_ins c
     WHERE c.attendee_id=a.id AND c.event_id=a.event_id AND c.deleted_at IS NULL) ci ON true
   WHERE a.event_id=p_event AND a.deleted_at IS NULL AND o.deleted_at IS NULL
   ORDER BY a.id LIMIT p_max_rows
 ) r;
 RETURN jsonb_build_object('sourceNamespace',p_namespace,'accountId',p_account::text,'eventId',p_event::text,
   'observedAt',statement_timestamp(),'rows',result);
END $$;
ALTER FUNCTION public.kampy_roster_event_snapshot_v1(text,bigint,bigint,integer) OWNER TO kampy_native_roster_owner;
REVOKE ALL ON FUNCTION public.kampy_roster_event_snapshot_v1(text,bigint,bigint,integer) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION public.kampy_roster_event_snapshot_v1(text,bigint,bigint,integer) TO kampy_native_roster_reader;

CREATE FUNCTION public.kampy_roster_contact_v1(p_namespace text,p_account bigint,p_event bigint,p_attendee bigint,p_fields text[]) RETURNS jsonb
LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path=pg_catalog AS $$
DECLARE result jsonb;
BEGIN
 IF NOT public.kampy_roster_event_allowed_v1(p_namespace,p_account,p_event) THEN
   RAISE EXCEPTION 'roster source unavailable' USING ERRCODE='42501';
 END IF;
 IF p_fields IS NULL OR NOT p_fields <@ ARRAY['roster.ticket_contact_email','roster.purchaser_identity','roster.purchaser_email']::text[] THEN
   RAISE EXCEPTION 'invalid roster fields' USING ERRCODE='22023';
 END IF;
 SELECT ('{}'::jsonb
   || CASE WHEN 'roster.ticket_contact_email'=ANY(p_fields) THEN jsonb_build_object('ticketContactEmail',NULLIF(a.email,'')) ELSE '{}'::jsonb END
   || CASE WHEN 'roster.purchaser_identity'=ANY(p_fields) THEN jsonb_build_object('purchaserName',NULLIF(btrim(o.first_name||' '||o.last_name),'')) ELSE '{}'::jsonb END
   || CASE WHEN 'roster.purchaser_email'=ANY(p_fields) THEN jsonb_build_object('purchaserEmail',NULLIF(o.email,'')) ELSE '{}'::jsonb END)
 INTO result FROM public.attendees a JOIN public.orders o ON o.id=a.order_id AND o.event_id=a.event_id
 WHERE a.id=p_attendee AND a.event_id=p_event AND a.deleted_at IS NULL AND o.deleted_at IS NULL;
 RETURN result;
END $$;
ALTER FUNCTION public.kampy_roster_contact_v1(text,bigint,bigint,bigint,text[]) OWNER TO kampy_native_roster_owner;
REVOKE ALL ON FUNCTION public.kampy_roster_contact_v1(text,bigint,bigint,bigint,text[]) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION public.kampy_roster_contact_v1(text,bigint,bigint,bigint,text[]) TO kampy_native_roster_reader;
REVOKE ALL ON public.kampy_roster_approved_events FROM PUBLIC,kampy_native_roster_reader;
REVOKE CREATE ON SCHEMA public FROM kampy_native_roster_owner;
