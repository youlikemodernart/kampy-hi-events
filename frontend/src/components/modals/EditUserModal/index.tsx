import {useForm} from "@mantine/form";
import {GenericModalProps, User,} from "../../../types.ts";
import {Modal} from "../../common/Modal";
import {Alert, Button, MultiSelect, Select, TextInput} from "@mantine/core";
import {useFormErrorResponseHandler} from "../../../hooks/useFormErrorResponseHandler.tsx";
import {t, Trans} from "@lingui/macro";
import {CustomSelect, ItemProps} from "../../common/CustomSelect";
import {IconUser, IconUserShield} from "@tabler/icons-react";
import {showSuccess} from "../../../utilites/notifications.tsx";
import {UpdateUserRequest} from "../../../api/user.client.ts";
import {useEditUser} from "../../../mutations/useEditUser.ts";
import {NavLink} from "react-router";
import {InputGroup} from "../../common/InputGroup";
import {useGetEvents} from "../../../queries/useGetEvents.ts";

const eventScopedRoles = ['ORGANIZER', 'REPORTING', 'CHECK_IN'];
const isEventScopedRole = (role?: string) => eventScopedRoles.includes(role ?? '');

interface EditUserModalProps extends GenericModalProps {
    user: User;
}

export const EditUserModal = ({onClose, user}: EditUserModalProps) => {
    const ediMutation = useEditUser();
    const formErrorHandler = useFormErrorResponseHandler();
    const eventsQuery = useGetEvents({perPage: 100, sortBy: 'start_date', sortDirection: 'asc'});

    const form = useForm<UpdateUserRequest>({
        initialValues: {
            first_name: user.first_name,
            last_name: user.last_name,
            status: String(user.status),
            role: String(user.role),
            event_ids: (user.assigned_event_ids ?? [])
                .filter(eventId => eventId !== undefined)
                .map(eventId => String(eventId)),
        },
        validate: {
            event_ids: (value, values) => isEventScopedRole(values.role) && (!value || value.length === 0)
                ? t`Select at least one event`
                : null,
        },
    });

    const eventOptions = (eventsQuery.data?.data ?? [])
        .filter(event => event.id !== undefined)
        .map(event => ({value: String(event.id), label: event.title}));

    const handleCreate = (values: UpdateUserRequest) => {
        ediMutation.mutate({
            userId: user.id,
            userData: {
                ...values,
                event_ids: isEventScopedRole(values.role) ? values.event_ids : [],
            },
        }, {
            onSuccess: () => {
                form.reset();
                onClose();
                showSuccess(<Trans>Success! {values.first_name} will receive an email shortly.</Trans>);
            },
            onError: (error) => formErrorHandler(form, error)
        });
    };

    const calcTypeOptions: ItemProps[] = [
        {
            icon: <IconUserShield/>,
            label: t`Admin`,
            value: 'ADMIN',
            description: t`Full access to team, account settings, billing, events, orders, reports, and check-in.`,
        },
        {
            icon: <IconUser/>,
            label: t`Event Manager`,
            value: 'ORGANIZER',
            description: t`Manage event setup, ticketing, guest operations, reports, messages, integrations, and check-in.`,
        },
        {
            icon: <IconUser/>,
            label: t`Finance`,
            value: 'FINANCE',
            description: t`View orders, attendees, reports, and exports. Can refund, cancel, and mark orders as paid.`,
        },
        {
            icon: <IconUser/>,
            label: t`Reporting`,
            value: 'REPORTING',
            description: t`Read-only access to event data, orders, attendees, reports, and exports.`,
        },
        {
            icon: <IconUser/>,
            label: t`Check-in Staff`,
            value: 'CHECK_IN',
            description: t`Can access check-in lists and check attendees in or out.`,
        },
    ];

    return (
        <Modal heading={t`Edit User`} onClose={onClose} opened>
            {user.status === 'INVITED' && (
                <Alert mb={20}>
                    <Trans>This user is not active, as they have not accepted their invitation.</Trans>
                </Alert>
            )}
            <form onSubmit={form.onSubmit(values => handleCreate(values))}>
                <fieldset disabled={ediMutation.isPending}>
                    <InputGroup>
                        <TextInput required {...form.getInputProps('first_name')} label={t`First Name`}/>
                        <TextInput required {...form.getInputProps('last_name')} label={t`Last Name`}/>
                    </InputGroup>

                    <TextInput
                        disabled
                        readOnly
                        value={user.email}
                        type={'email'}
                        label={t`Email`}
                        description={<Trans>Users can change their email in <NavLink target={'_blank'}
                                                                                     to={'/manage/profile'}>Profile
                            Settings</NavLink></Trans>}
                    />

                    {user.is_account_owner && (
                        <Alert mb={20}>
                            {t`You cannot edit the role or status of the account owner.`}
                        </Alert>
                    )}

                    <CustomSelect
                        label={t`Role`}
                        optionList={calcTypeOptions}
                        form={form}
                        name={'role'}
                        disabled={user.is_account_owner}
                    />

                    {isEventScopedRole(form.values.role) && (
                        <MultiSelect
                            required
                            searchable
                            disabled={user.is_account_owner}
                            label={t`Assigned events`}
                            description={t`This user will only see and operate on the selected events.`}
                            placeholder={eventsQuery.isLoading ? t`Loading events...` : t`Select events`}
                            data={eventOptions}
                            {...form.getInputProps('event_ids')}
                        />
                    )}

                    {user.status !== 'INVITED' && (
                        <Select
                            disabled={user.is_account_owner}
                            label={t`Status`}
                            placeholder={t`Select status`}
                            required
                            {...form.getInputProps('status')}
                            description={t`Inactive users cannot log in.`}

                            data={[
                                {value: 'ACTIVE', label: t`Active`},
                                {value: 'INACTIVE', label: t`Inactive`},
                            ]}
                        />
                    )}
                </fieldset>
                <Button
                    fullWidth
                    loading={ediMutation.isPending}
                    type={'submit'}>
                    {t`Edit User`}
                </Button>
            </form>
        </Modal>
    )
}
