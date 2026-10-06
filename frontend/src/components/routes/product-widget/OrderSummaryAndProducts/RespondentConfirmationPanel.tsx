import {useState} from 'react';
import {useMutation} from '@tanstack/react-query';
import {Alert, Button, Stack, Text, Title} from '@mantine/core';
import {t} from '@lingui/macro';
import {publicApi} from '../../../../api/public-client.ts';
import {Order} from '../../../../types.ts';
import {Card} from '../../../common/Card';

export function RespondentConfirmationPanel({order}: {order: Order}) {
    const [message, setMessage] = useState('');
    const invitation = useMutation({
        mutationFn: async () => (await publicApi.post(`/registration/orders/${encodeURIComponent(order.short_id)}/request-verification`, {}, {headers: {'X-Kamp-Respondent-Intent': 'confirm'}})).data,
        onSuccess: result => setMessage(result.message),
        onError: () => setMessage(t`Unable to open the waiver invitation. Please contact Kamp Love for help.`),
    });
    return <Card><Stack gap="md">
        <Title order={2}>{t`Complete your waivers`}</Title>
        <Text>{t`We send the private invitation to the purchase email address. Choose the appropriate adult or guardian for each attendee, then continue to their waiver.`}</Text>
        {message && <Alert role="status">{message}</Alert>}
        <Button loading={invitation.isPending} onClick={() => invitation.mutate()}>{t`Send waiver invitation`}</Button>
    </Stack></Card>;
}
