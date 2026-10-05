import {useState} from 'react';
import {useMutation} from '@tanstack/react-query';
import {Alert, Button, Checkbox, Select, Stack, Text, TextInput, Title} from '@mantine/core';
import {t} from '@lingui/macro';
import {publicApi} from '../../../../api/public-client.ts';
import {Order} from '../../../../types.ts';
import {Card} from '../../../common/Card';

export function RespondentConfirmationPanel({order}: {order: Order}) {
    const attendees = (order.attendees ?? []).filter(attendee => attendee.status === 'ACTIVE');
    const [code, setCode] = useState('');
    const [acknowledged, setAcknowledged] = useState(false);
    const [message, setMessage] = useState('');
    const [confirmed, setConfirmed] = useState(false);
    const [rows, setRows] = useState<Record<string, {route: string; respondent_name: string; email: string}>>({});
    const headers = {'X-Kamp-Respondent-Intent': 'confirm'};
    const post = async (path: string, body: unknown) => {
        try {return (await publicApi.post(path, body, {headers})).data;}
        catch {throw new Error('Respondent confirmation unavailable');}
    };
    const request = useMutation({
        mutationFn: () => post(`/registration/orders/${encodeURIComponent(order.short_id)}/request-verification`, {}),
        onSuccess: result => setMessage(result.message),
        onError: () => setMessage(t`Unable to confirm contacts`),
    });
    const confirmation = useMutation({
        mutationFn: () => post(`/registration/orders/${encodeURIComponent(order.short_id)}/confirm-respondents`, {
            verification_code: code.trim(), acknowledged,
            respondents: attendees.map(attendee => ({attendee_id: Number(attendee.id), ...rows[String(attendee.id)]})),
        }),
        onSuccess: () => {setConfirmed(true); setCode(''); setRows({}); setMessage(t`Contacts confirmed`);},
        onError: () => {setCode(''); setMessage(t`Unable to confirm contacts`);},
    });
    const update = (id: string, key: string, value: string) => setRows(previous => ({...previous, [id]: {...(previous[id] ?? {route: '', respondent_name: '', email: ''}), [key]: value}}));
    return <Card><Stack gap="md">
        <Title order={2}>{t`Waiver contacts`}</Title>
        <Text>{t`Every active attendee needs a contact. Email verification does not prove guardianship or sign a waiver.`}</Text>
        {message && <Alert role="status">{message}</Alert>}
        {!confirmed && <>
            <Button loading={request.isPending} onClick={() => request.mutate()}>{t`Verify purchase email`}</Button>
            {attendees.map(attendee => {
                const id = String(attendee.id);
                return <Stack gap="sm" key={id}>
                    <Text fw={600}>{attendee.first_name} {attendee.last_name}</Text>
                    <Select aria-label={`${attendee.first_name} ${attendee.last_name}`} value={rows[id]?.route ?? null} onChange={value => update(id, 'route', value ?? '')} data={[{value: 'adult', label: t`Adult attendee`}, {value: 'guardian', label: t`Parent or guardian`}]} required/>
                    {rows[id]?.route === 'guardian' && <TextInput label={t`Name`} value={rows[id]?.respondent_name ?? ''} onChange={event => update(id, 'respondent_name', event.currentTarget.value)} maxLength={200} required/>}
                    <TextInput label={t`Email`} type="email" value={rows[id]?.email ?? ''} onChange={event => update(id, 'email', event.currentTarget.value)} maxLength={320} required/>
                </Stack>;
            })}
            <TextInput label={t`Verification code`} value={code} onChange={event => setCode(event.currentTarget.value)} maxLength={64} autoComplete="off" spellCheck={false}/>
            <Checkbox label={t`I confirm the appropriate contact for every attendee.`} checked={acknowledged} onChange={event => setAcknowledged(event.currentTarget.checked)}/>
            <Button disabled={!acknowledged || !/^[a-f0-9]{64}$/.test(code.trim()) || attendees.some(attendee => !rows[String(attendee.id)]?.route || !rows[String(attendee.id)]?.email)} loading={confirmation.isPending} onClick={() => confirmation.mutate()}>{t`Confirm all contacts`}</Button>
        </>}
    </Stack></Card>;
}
