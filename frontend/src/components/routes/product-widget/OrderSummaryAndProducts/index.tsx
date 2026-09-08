import {t, Trans} from "@lingui/macro";
import {NavLink, useNavigate, useParams, useLocation} from "react-router";
import {ActionIcon, Alert, Button, Group, SimpleGrid, Text, Tooltip} from "@mantine/core";
import {
    IconBuilding,
    IconCalendar,
    IconCalendarEvent,
    IconCash,
    IconCheck,
    IconClock,
    IconEdit,
    IconExternalLink,
    IconId,
    IconMail,
    IconMapPin,
    IconMenuOrder,
    IconPrinter,
    IconSend,
    IconTicket,
    IconUser
} from "@tabler/icons-react";
import {useEffect, useState} from "react";
import {useQueryClient} from "@tanstack/react-query";

import {useGetOrderPublic, GET_ORDER_PUBLIC_QUERY_KEY} from "../../../../queries/useGetOrderPublic.ts";
import {eventCheckoutPath, eventHomepagePath} from "../../../../utilites/urlHelper.ts";
import {dateToBrowserTz} from "../../../../utilites/dates.ts";
import {formatAddress} from "../../../../utilites/addressUtilities.ts";
import {getAttendeeProductTitle} from "../../../../utilites/products.ts";
import {showSuccess, showError} from "../../../../utilites/notifications.tsx";

import {Card} from "../../../common/Card";
import {LoadingMask} from "../../../common/LoadingMask";
import {HomepageInfoMessage} from "../../../common/HomepageInfoMessage";
import {PoweredByFooter} from "../../../common/PoweredByFooter";
import {EventDateRange} from "../../../common/EventDateRange";
import {OnlineEventDetails} from "../../../common/OnlineEventDetails";
import {AddToCalendarCTA} from "../../../common/AddToCalendarCTA";
import {InlineOrderSummary} from "../../../common/InlineOrderSummary";
import {CheckoutContent} from "../../../layouts/Checkout/CheckoutContent";
import {EditAttendeeModal} from "./EditAttendeeModal";
import {EditOrderModal} from "./EditOrderModal";

import {useEditAttendeePublic} from "../../../../mutations/useEditAttendeePublic";
import {useEditOrderPublic} from "../../../../mutations/useEditOrderPublic";
import {useResendAttendeeTicketPublic} from "../../../../mutations/useResendAttendeeTicketPublic";
import {useResendOrderConfirmationPublic} from "../../../../mutations/useResendOrderConfirmationPublic";

import {Attendee, Event, Order, Product} from "../../../../types.ts";
import classes from './OrderSummaryAndProducts.module.scss';
import {getConfirmationHeadline} from "../../../../utilites/confirmationCopy.ts";
import {CheckoutDocumentHead} from "../../../common/CheckoutDocumentHead";
import {eventSupportEmail, privacyUrl, termsUrl} from "../../../../utilites/branding.ts";
import {clearWaitlistJoinedForEvent} from "../../../../hooks/useWaitlistJoined.ts";
// Purchase tracking is handled by the parent Checkout layout

const PaymentStatus = ({order}: { order: Order }) => {
    const paymentStatuses: Record<string, string> = {
        'NO_PAYMENT_REQUIRED': t`No Payment Required`,
        'AWAITING_PAYMENT': t`Awaiting Payment`,
        'PAYMENT_FAILED': t`Payment Failed`,
        'PAYMENT_RECEIVED': t`Payment Received`,
        'AWAITING_OFFLINE_PAYMENT': t`Awaiting Offline Payment`,
    };

    return order?.payment_status ? <span>{paymentStatuses[order.payment_status] || ''}</span> : null;
};

const RefundStatusType = ({order}: { order: Order }) => {
    const refundStatuses: Record<string, string> = {
        'REFUND_PENDING': t`Refund Pending`,
        'REFUND_FAILED': t`Refund Failed`,
        'REFUNDED': t`Refunded`,
        'PARTIALLY_REFUNDED': t`Partially Refunded`,
    };

    return order?.refund_status ? <span>{refundStatuses[order.refund_status] || ''}</span> : null;
};

const GuestListItem = ({
    attendee,
    event,
    allowSelfEdit,
    onEditClick,
    onResendClick,
}: {
    attendee: Attendee;
    event: Event;
    allowSelfEdit: boolean;
    onEditClick: () => void;
    onResendClick: () => void;
}) => {
    const productTitle = getAttendeeProductTitle(attendee, attendee.product as Product);
    const isCancelled = attendee.status === 'CANCELLED';

    return (
        <div className={`${classes.guestItem} ${isCancelled ? classes.guestItemCancelled : ''}`}>
            <div className={classes.guestInfo}>
                <div className={classes.guestName}>
                    {attendee.first_name} {attendee.last_name}
                    {isCancelled && <span className={classes.cancelledBadge}>{t`Cancelled`}</span>}
                </div>
                <div className={classes.guestDetails}>
                    <span className={classes.guestEmail}>{attendee.email}</span>
                    <span className={classes.guestProduct}>{productTitle}</span>
                </div>
            </div>
            <div className={classes.guestActions}>
                <Tooltip label={t`View Ticket`}>
                    <ActionIcon
                        variant="subtle"
                        aria-label={t`View Ticket`}
                        onClick={() => window?.open(`/product/${event.id}/${attendee.short_id}`, '_blank')}
                    >
                        <IconExternalLink size={18}/>
                    </ActionIcon>
                </Tooltip>
                <Tooltip label={t`Print Ticket`}>
                    <ActionIcon
                        variant="subtle"
                        aria-label={t`Print Ticket`}
                        onClick={() => window?.open(`/product/${event.id}/${attendee.short_id}/print`, '_blank')}
                    >
                        <IconPrinter size={18}/>
                    </ActionIcon>
                </Tooltip>
                {allowSelfEdit && !isCancelled && (
                    <>
                        <Tooltip label={t`Edit Attendee`}>
                            <ActionIcon
                                variant="subtle"
                                aria-label={t`Edit Attendee`}
                                onClick={onEditClick}
                            >
                                <IconEdit size={18}/>
                            </ActionIcon>
                        </Tooltip>
                        <Tooltip label={t`Resend Ticket`}>
                            <ActionIcon
                                variant="subtle"
                                aria-label={t`Resend Ticket`}
                                onClick={onResendClick}
                            >
                                <IconSend size={18}/>
                            </ActionIcon>
                        </Tooltip>
                    </>
                )}
            </div>
        </div>
    );
};

const DetailItem = ({icon: Icon, label, value}: { icon: any, label: string, value: React.ReactNode }) => (
    <div className={classes.detailItem}>
        <Group gap="xs" wrap="nowrap">
            <Icon size={20} style={{color: 'var(--checkout-accent, var(--mantine-color-gray-6))', flexShrink: 0}}/>
            <div className={classes.detailContent}>
                <Text size="sm" c="dimmed" className={classes.label}>{label}</Text>
                <Text className={classes.value}>{value}</Text>
            </div>
        </Group>
    </div>
);

/**
 * Confirmation headline, Rule C-1.
 *
 * Each state is ONE complete translatable message with named placeholders, not a
 * fragment with the event title concatenated on. Translators need to reorder the
 * event name and the venue relative to the surrounding words, and a concatenation
 * makes that impossible.
 */
const WelcomeHeader = ({order, event, allowSelfEdit}: { order: Order; event: Event; allowSelfEdit: boolean }) => {
    const headline = getConfirmationHeadline(order, event);

    if (headline.kind === 'none') return null;

    const isCompleted = order.status === 'COMPLETED';
    const isAwaitingPayment = order.status === 'AWAITING_OFFLINE_PAYMENT';
    const isCancelled = order.status === 'CANCELLED';

    const eventTitle = headline.eventTitle;
    const venueName = headline.venueName ?? '';

    const emoji = isCompleted ? '🎉' : isAwaitingPayment ? '⏳' : '😔';

    return (
        <div className={classes.welcomeHeader}>
            {/*
              Decorative: the headline below says the same thing in words. Without
              aria-hidden, screen readers announce the Unicode name ("party popper")
              as though it were content.
            */}
            <div className={classes.confettiIcon} aria-hidden="true">
                {/* eslint-disable-next-line lingui/no-unlocalized-strings */}
                <span>{emoji}</span>
            </div>

            {/*
              This sentence is what happened to the buyer, so it is the page's h1.
              It was previously a div while four subsection labels were h1s.
            */}
            <h1 className={classes.welcomeMessage}>
                {headline.kind === 'going_to_venue' && (
                    <Trans>You're going to {eventTitle} at {venueName}</Trans>
                )}
                {headline.kind === 'going_to' && (
                    <Trans>You're going to {eventTitle}</Trans>
                )}
                {headline.kind === 'online' && (
                    <Trans>You're all set for {eventTitle}</Trans>
                )}
                {headline.kind === 'awaiting_payment' && (
                    <Trans>Your spot at {eventTitle} is being held</Trans>
                )}
                {headline.kind === 'cancelled' && (
                    <Trans>Your order for {eventTitle} has been cancelled</Trans>
                )}
            </h1>

            {isCompleted && (
                <div className={classes.confirmationText}>
                    {t`Confirmation sent to`} <strong>{order.email}</strong>
                </div>
            )}
            {isCompleted && allowSelfEdit && (
                <div className={classes.selfServiceHint}>
                    {t`Bookmark this page to manage your order anytime.`}
                </div>
            )}
            {isCancelled && (
                <div className={classes.confirmationText}>
                    {t`A cancellation notice has been sent to`} <strong>{order.email}</strong>
                </div>
            )}
        </div>
    );
};

const OrderDetails = ({
    order,
    event,
    allowSelfEdit,
    onEditClick,
    onResendClick,
}: {
    order: Order;
    event: Event;
    allowSelfEdit: boolean;
    onEditClick: () => void;
    onResendClick: () => void;
}) => (
    <Card style={{marginBottom: '40px'}}>
        <SimpleGrid cols={{base: 1, sm: 2}} spacing="md">
            <DetailItem
                icon={IconUser}
                label={t`Name`}
                value={
                    <Group gap="xs" wrap="nowrap">
                        <span>{order.first_name} {order.last_name}</span>
                        {allowSelfEdit && order.status !== 'CANCELLED' && (
                            <Tooltip label={t`Edit`}>
                                <ActionIcon size="xs" variant="subtle" aria-label={t`Edit`} onClick={onEditClick}>
                                    <IconEdit size={14}/>
                                </ActionIcon>
                            </Tooltip>
                        )}
                    </Group>
                }
            />
            <DetailItem
                icon={IconId}
                label={t`Order Reference`}
                value={order.public_id}
            />
            <DetailItem
                icon={IconMail}
                label={t`Email`}
                value={
                    <Group gap="xs" wrap="nowrap">
                        <span style={{wordBreak: 'break-all'}}>{order.email}</span>
                        {allowSelfEdit && order.status !== 'CANCELLED' && (
                            <Tooltip label={t`Resend Confirmation`}>
                                <ActionIcon size="xs" variant="subtle" aria-label={t`Resend Confirmation`} onClick={onResendClick}>
                                    <IconSend size={14}/>
                                </ActionIcon>
                            </Tooltip>
                        )}
                    </Group>
                }
            />
            <DetailItem
                icon={IconCalendar}
                label={t`Order Date`}
                value={dateToBrowserTz(order.created_at, event.timezone)}
            />
            {!!order.refund_status && (
                <DetailItem
                    icon={IconMenuOrder}
                    label={t`Refund Status`}
                    value={<RefundStatusType order={order}/>}
                />
            )}
            {(order.payment_status !== 'PAYMENT_RECEIVED' && order.payment_status !== 'NO_PAYMENT_REQUIRED') && (
                <DetailItem
                    icon={IconCash}
                    label={t`Payment Status`}
                    value={<PaymentStatus order={order}/>}
                />
            )}
            {order.address && (
                <DetailItem
                    icon={IconMapPin}
                    label={t`Billing Address`}
                    value={formatAddress(order.address)}
                />
            )}
        </SimpleGrid>
    </Card>
);

const EventDetails = ({event}: { event: Event }) => {
    const location = event.settings?.location_details ? formatAddress(event.settings.location_details) : null;
    const venueDetails = event.settings?.location_details?.venue_name
        ? `${event.settings.location_details.venue_name}${location ? `, ${location}` : ''}`
        : location;

    return (
        <Card>
            <SimpleGrid cols={{base: 1, sm: 2}} spacing="md">
                <DetailItem
                    icon={IconCalendarEvent}
                    label={t`Event Date`}
                    value={<EventDateRange event={event}/>}
                />
                {venueDetails && (
                    <DetailItem
                        icon={IconMapPin}
                        label={t`Location`}
                        value={(
                            <NavLink
                                to={event.settings?.maps_url || `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(event?.settings?.location_details ? formatAddress(event.settings.location_details) : '')}`}
                                target="_blank"
                            >
                                {venueDetails}
                            </NavLink>

                        )}
                    />
                )}
                <DetailItem
                    icon={IconClock}
                    label={t`Timezone`}
                    value={event.timezone}
                />
                <DetailItem
                    icon={IconBuilding}
                    label={t`Organizer`}
                    value={(
                        <>
                            {event.organizer?.email && (
                                <NavLink to={event.organizer?.email ? `mailto:${event.organizer.email}` : '#'}>
                                    {event.organizer?.name}
                                </NavLink>
                            )}
                            {!event.organizer?.email && event.organizer?.name}
                        </>
                    )}
                />
            </SimpleGrid>
        </Card>
    );
};

const OrderStatus = ({order}: { order: Order }) => {
    if (order?.status === 'CANCELLED') {
        return (
            <HomepageInfoMessage
                status="cancelled"
                message={t`Order cancelled`}
                subtitle={t`This order has been cancelled.`}
            />
        );
    }

    if (order?.status === 'COMPLETED') {
        return (
            <HomepageInfoMessage
                status="success"
                message={t`Order complete`}
                subtitle={t`This order is complete.`}
            />
        );
    }

    return (
        <HomepageInfoMessage
            status="processing"
            message={t`Processing order`}
            subtitle={t`This order is being processed.`}
        />
    );
};

const PostCheckoutMessage = ({ message }: { message: string }) => (
    <div style={{ marginTop: '20px', marginBottom: '40px' }}>
        <h2 className={classes.heading}>{t`Additional Information`}</h2>
        <Card>
            <div dangerouslySetInnerHTML={{ __html: message }} />
        </Card>
    </div>
);

const OfflinePaymentInstructions = ({ event }: { event: Event }) => (
    <div style={{ marginTop: '20px', marginBottom: '40px' }}>
        <h2>{t`Payment Instructions`}</h2>
        <Card>
            <div
                dangerouslySetInnerHTML={{
                    __html: event?.settings?.offline_payment_instructions || "",
                }}
            />
        </Card>
    </div>
);

export const OrderSummaryAndProducts = () => {
    const {eventId, orderShortId} = useParams();
    const location = useLocation();
    const {data: order, isFetched: orderIsFetched, isError} = useGetOrderPublic(eventId, orderShortId, ['event']);
    const event = order?.event;
    const navigate = useNavigate();
    const queryClient = useQueryClient();
    const emailUpdated = location.state?.emailUpdated === true;

    const [editingAttendee, setEditingAttendee] = useState<Attendee | null>(null);
    const [editOrderModalOpened, setEditOrderModalOpened] = useState(false);

    useEffect(() => {
        if (eventId && order && (order.status === 'COMPLETED' || order.status === 'AWAITING_OFFLINE_PAYMENT')) {
            clearWaitlistJoinedForEvent(eventId);
        }
    }, [eventId, order?.status]);

    const editAttendeeMutation = useEditAttendeePublic();
    const editOrderMutation = useEditOrderPublic();
    const resendAttendeeTicketMutation = useResendAttendeeTicketPublic();
    const resendOrderConfirmationMutation = useResendOrderConfirmationPublic();

    const allowSelfEdit = event?.settings?.allow_attendee_self_edit ?? false;

    const handleEditAttendee = (attendee: Attendee, data: any) => {
        editAttendeeMutation.mutate(
            {
                eventId: eventId!,
                orderShortId: orderShortId!,
                attendeeShortId: attendee.short_id,
                data,
            },
            {
                onSuccess: (result) => {
                    queryClient.invalidateQueries({queryKey: [GET_ORDER_PUBLIC_QUERY_KEY]});
                    setEditingAttendee(null);
                    showSuccess(result.message || t`Attendee updated successfully`);
                    if (result.warning) {
                        showError(result.warning);
                    }
                },
                onError: (error: any) => {
                    if (error?.response?.status === 429) {
                        showError(t`Rate limit exceeded. Please try again later.`);
                    } else {
                        showError(error?.response?.data?.message || t`Failed to update attendee`);
                    }
                },
            }
        );
    };

    const handleEditOrder = (data: any) => {
        editOrderMutation.mutate(
            {
                eventId: eventId!,
                orderShortId: orderShortId!,
                data,
            },
            {
                onSuccess: (result) => {
                    setEditOrderModalOpened(false);
                    showSuccess(result.message || t`Order updated successfully`);

                    if (result.new_short_id) {
                        navigate(`/checkout/${eventId}/${result.new_short_id}/summary`, {
                            state: { emailUpdated: true }
                        });
                    } else {
                        queryClient.invalidateQueries({queryKey: [GET_ORDER_PUBLIC_QUERY_KEY]});
                    }

                    if (result.warning) {
                        showError(result.warning);
                    }
                },
                onError: (error: any) => {
                    if (error?.response?.status === 429) {
                        showError(t`Rate limit exceeded. Please try again later.`);
                    } else {
                        showError(error?.response?.data?.message || t`Failed to update order`);
                    }
                },
            }
        );
    };

    const handleResendAttendeeTicket = (attendee: Attendee) => {
        if (!window.confirm(t`Are you sure you want to resend the ticket to ${attendee.email}?`)) {
            return;
        }
        resendAttendeeTicketMutation.mutate(
            {
                eventId: eventId!,
                orderShortId: orderShortId!,
                attendeeShortId: attendee.short_id,
            },
            {
                onSuccess: (result) => {
                    showSuccess(result.message || t`Ticket resent successfully`);
                },
                onError: (error: any) => {
                    if (error?.response?.status === 429) {
                        showError(t`Rate limit exceeded. Please try again later.`);
                    } else {
                        showError(error?.response?.data?.message || t`Failed to resend ticket`);
                    }
                },
            }
        );
    };

    const handleResendOrderConfirmation = () => {
        if (!window.confirm(t`Are you sure you want to resend the order confirmation to ${order?.email}?`)) {
            return;
        }
        resendOrderConfirmationMutation.mutate(
            {
                eventId: eventId!,
                orderShortId: orderShortId!,
            },
            {
                onSuccess: (result) => {
                    showSuccess(result.message || t`Order confirmation resent successfully`);
                },
                onError: (error: any) => {
                    if (error?.response?.status === 429) {
                        showError(t`Rate limit exceeded. Please try again later.`);
                    } else {
                        showError(error?.response?.data?.message || t`Failed to resend order confirmation`);
                    }
                },
            }
        );
    };

    if (isError) {
        return (
            <HomepageInfoMessage
                status="not_found"
                message={t`Order Not Found`}
                subtitle={t`We couldn't find the order you're looking for. The link may have expired or the order details may have changed.`}
                link={order?.event ? eventHomepagePath(order.event) : undefined}
                linkText={order?.event ? t`Back to Event` : undefined}
                supportEmail={eventSupportEmail(order?.event)}
            />
        );
    }

    if (!orderIsFetched || !order || !event) {
        return <LoadingMask/>;
    }

    if (window?.location.search.includes('failed') || order?.payment_status === 'PAYMENT_FAILED') {
        navigate(eventCheckoutPath(eventId, orderShortId, 'payment') + '?payment_failed=true');
        return;
    }

    if (order?.status !== 'COMPLETED' && order?.status !== 'CANCELLED' && order?.status !== 'AWAITING_OFFLINE_PAYMENT') {
        return <OrderStatus order={order}/>;
    }

    return (
        <>
            <CheckoutContent>
                <CheckoutDocumentHead title={t`Your order`} eventTitle={event.title}/>
                <WelcomeHeader order={order} event={event} allowSelfEdit={allowSelfEdit}/>

                {emailUpdated && (
                    <Alert
                        icon={<IconCheck size={16}/>}
                        color="green"
                        mb="lg"
                        radius="lg"
                        style={{
                            backgroundColor: 'var(--checkout-ok-soft, var(--kamp-ok-tint, #e7f0ea))',
                            borderColor: 'var(--checkout-ok, var(--kamp-ok, #2f6b4f))',
                        }}
                    >
                        <Text size="sm" style={{color: 'var(--checkout-text-primary, var(--kamp-ink, #171717))'}}>
                            {t`Your order details have been updated. A confirmation email has been sent to the new email address.`}
                        </Text>
                    </Alert>
                )}

                <InlineOrderSummary
                    event={event}
                    order={order}
                    showBuyerProtection={false}
                    defaultExpanded={false}
                />

                {order?.status === 'AWAITING_OFFLINE_PAYMENT' && <OfflinePaymentInstructions event={event}/>}

                <h2 className={classes.heading}>{t`Order Details`}</h2>

                <OrderDetails
                    order={order}
                    event={event}
                    allowSelfEdit={allowSelfEdit}
                    onEditClick={() => setEditOrderModalOpened(true)}
                    onResendClick={handleResendOrderConfirmation}
                />

                {event?.settings?.is_online_event && <OnlineEventDetails eventSettings={event.settings}/>}

                {!!event?.settings?.post_checkout_message && <PostCheckoutMessage message={event.settings.post_checkout_message}/>}

                <h2 className={classes.heading}>{t`Event Details`}</h2>
                <EventDetails event={event}/>

                {order.status === 'COMPLETED' && <AddToCalendarCTA event={event}/>}

                {(order?.attendees && order.attendees.length > 0) && (
                    <>
                        <Group justify="space-between" align="center">
                            <h2 className={classes.heading}>
                                <Group gap="xs">
                                    <IconTicket size={20}/>
                                    {t`Guests`}
                                </Group>
                            </h2>
                            <Button
                                size="sm"
                                variant="subtle"
                                leftSection={<IconPrinter size={16}/>}
                                onClick={() => window?.open(`/order/${eventId}/${orderShortId}/print`, '_blank')}
                            >
                                {t`Print All Tickets`}
                            </Button>
                        </Group>

                        <Card>
                            <div className={classes.guestList}>
                                {order.attendees.map((attendee) => (
                                    <GuestListItem
                                        key={attendee.id}
                                        attendee={attendee}
                                        event={event}
                                        allowSelfEdit={allowSelfEdit}
                                        onEditClick={() => setEditingAttendee(attendee)}
                                        onResendClick={() => handleResendAttendeeTicket(attendee)}
                                    />
                                ))}
                            </div>
                        </Card>
                    </>
                )}

                <div className={classes.legalLinks}>
                    <a href={privacyUrl()} target="_blank" rel="noopener noreferrer">{t`Privacy Policy`}</a>
                    <a href={termsUrl()} target="_blank" rel="noopener noreferrer">{t`Terms of Service`}</a>
                </div>

                <PoweredByFooter/>
            </CheckoutContent>

            {editingAttendee && (
                <EditAttendeeModal
                    opened={!!editingAttendee}
                    onClose={() => setEditingAttendee(null)}
                    attendee={editingAttendee}
                    onSuccess={(values: any) => {
                        handleEditAttendee(editingAttendee, values);
                    }}
                />
            )}

            {order && (
                <EditOrderModal
                    opened={editOrderModalOpened}
                    onClose={() => setEditOrderModalOpened(false)}
                    order={order}
                    onSuccess={(values: any) => {
                        handleEditOrder(values);
                    }}
                />
            )}
        </>
    );
};

export default OrderSummaryAndProducts;
