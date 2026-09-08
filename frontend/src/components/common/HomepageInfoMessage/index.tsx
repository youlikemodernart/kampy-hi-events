import {Button} from "@mantine/core";
import {IconArrowRight} from "@tabler/icons-react";
import {Trans} from "@lingui/macro";
import classes from './HomepageInfoMessage.module.scss';
import React from "react";

type StatusType =
    | 'info'
    | 'processing'
    | 'success'
    | 'warning'
    | 'error'
    | 'expired'
    | 'cancelled'
    | 'not_found'
    | 'awaiting_payment'
    | 'offline_payment';

const getStatusEmoji = (status: StatusType): string => {
    const emojis: Record<StatusType, string> = {
        info: '💬',
        processing: '⏳',
        success: '🎉',
        warning: '⚠️',
        error: '❌',
        expired: '⏰',
        cancelled: '😔',
        not_found: '🔍',
        awaiting_payment: '💳',
        offline_payment: '🏦',
    };
    return emojis[status] || emojis.info;
};

interface HomepageInfoMessageProps {
    message: React.ReactNode;
    subtitle?: string;
    link?: string;
    linkText?: string;
    status?: StatusType;
    /** Optional support route, so a terminal state never leaves the buyer without a human. */
    supportEmail?: string | null;
}

export const HomepageInfoMessage = ({
                                        message,
                                        subtitle,
                                        link,
                                        linkText,
                                        status = 'info',
                                        supportEmail,
                                    }: HomepageInfoMessageProps) => {
    const emoji = getStatusEmoji(status);

    return (
        <div className={classes.container}>
            <div className={classes.card}>
                {/*
                  The emoji is decorative: the title below states the same thing in
                  words. Without aria-hidden, screen readers announce the Unicode
                  name ("party popper") as if it were content.
                */}
                <div className={classes.emojiContainer} data-status={status} aria-hidden="true">
                    <span className={classes.emoji}>{emoji}</span>
                </div>

                {/*
                  This is the page heading, not a section label. Every call site
                  returns this component in place of the whole page or step body,
                  and no such page renders a competing h1. It is also what renders
                  for RESERVED and ABANDONED orders on the summary route, which the
                  order-status guard sends here before WelcomeHeader is reached, so
                  those states would otherwise have no h1 at all.
                */}
                <h1 className={classes.title}>{message}</h1>

                {subtitle && (
                    <p className={classes.subtitle}>{subtitle}</p>
                )}

                {(link && linkText) && (
                    <Button
                        component="a"
                        href={link}
                        rightSection={<IconArrowRight size={16}/>}
                        className={classes.button}
                    >
                        {linkText}
                    </Button>
                )}

                {supportEmail && (
                    <p className={classes.helpText}>
                        <Trans>
                            Still stuck?{' '}
                            <a className={classes.helpLink} href={`mailto:${supportEmail}`}>
                                Email us
                            </a>{' '}
                            and we'll help.
                        </Trans>
                    </p>
                )}
            </div>
        </div>
    );
};
