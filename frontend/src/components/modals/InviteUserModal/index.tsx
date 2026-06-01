import {useForm} from "@mantine/form";
import {GenericModalProps, InviteUserRequest,} from "../../../types.ts";
import {Modal} from "../../common/Modal";
import {Button, SimpleGrid, TextInput} from "@mantine/core";
import {useFormErrorResponseHandler} from "../../../hooks/useFormErrorResponseHandler.tsx";
import {t, Trans} from "@lingui/macro";
import {useInviteUser} from "../../../mutations/useInviteUser.ts";
import {CustomSelect, ItemProps} from "../../common/CustomSelect";
import {IconUser, IconUserShield} from "@tabler/icons-react";
import {showSuccess} from "../../../utilites/notifications.tsx";

export const InviteUserModal = ({onClose}: GenericModalProps) => {
    const createMutation = useInviteUser();
    const formErrorHandler = useFormErrorResponseHandler();

    const form = useForm<InviteUserRequest>({
        initialValues: {
            email: '',
            first_name: '',
            last_name: '',
            role: 'ADMIN',
        },
    });

    const handleCreate = (values: InviteUserRequest) => {
        createMutation.mutate({
            inviteUserData: values,
        }, {
            onSuccess: () => {
                form.reset();
                onClose();
                showSuccess(<Trans>Success! {values.first_name} will receive an email shortly.</Trans>);
            },
            onError: (error: any) => formErrorHandler(form, error)
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
        <Modal heading={t`Invite a team member`} onClose={onClose} opened modalHeader={'branded'}>
            <form onSubmit={form.onSubmit(values => handleCreate(values))}>
                <SimpleGrid cols={2}>
                    <TextInput required {...form.getInputProps('first_name')} label={t`First Name`}/>
                    <TextInput  {...form.getInputProps('last_name')} label={t`Last Name`}/>
                </SimpleGrid>

                <TextInput required type={'email'}  {...form.getInputProps('email')} label={t`Email`}/>

                <CustomSelect
                    label={t`Role`}
                    optionList={calcTypeOptions}
                    form={form}
                    name={'role'}
                />

                <Button
                    fullWidth
                    loading={createMutation.isPending}
                    type={'submit'}>
                    {t`Invite Team Member`}
                </Button>
            </form>
        </Modal>
    )
}
