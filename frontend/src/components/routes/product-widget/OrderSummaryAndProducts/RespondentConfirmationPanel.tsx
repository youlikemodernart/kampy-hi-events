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
        onError: () => setMessage(t`We couldn't send the waiver link just now. Try again in a minute, or email kampy@kamplove.org for help.`),
    });
    return <Card><Stack gap="md">
        <Title order={2}>{t`Complete a waiver for each attendee`}</Title>
        <Text>{t`Buying a ticket doesn't sign the waiver. We'll email a private link to the address you used at checkout. From there you pick who signs for each attendee: an adult signs for themselves, and a parent or guardian signs for a child. Then you can go straight to each waiver.`}</Text>
        {message && <Alert role="status">{message}</Alert>}
        <Button loading={invitation.isPending} onClick={() => invitation.mutate()}>{t`Email the waiver link`}</Button>
    </Stack></Card>;
}
