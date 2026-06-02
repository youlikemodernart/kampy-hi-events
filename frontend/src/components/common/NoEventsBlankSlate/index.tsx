import {t} from "@lingui/macro";
import {Button} from "@mantine/core";
import {IconPlus} from "@tabler/icons-react";
import {NoResultsSplash} from "../NoResultsSplash";

interface NoEventsBlankSlateProps {
    eventsState?: 'upcoming' | 'ended' | 'archived' | string
    openCreateModal: () => void;
    showCreateButton?: boolean;
}

export const NoEventsBlankSlate = ({eventsState, openCreateModal, showCreateButton = true}: NoEventsBlankSlateProps) => {
    return (
        <NoResultsSplash
            heading={t`No events to show`}
            imageHref={'/blank-slate/events.svg'}
            subHeading={showCreateButton ? (
                <>
                    <p>
                        {(eventsState === 'upcoming' || !eventsState) && t`Once you create an event, you'll see it here.`}
                        {eventsState === 'ended' && t`No ended events to show.`}
                        {eventsState === 'archived' && t`No archived events to show.`}
                    </p>
                    <Button
                        size={'xs'}
                        leftSection={<IconPlus/>}
                        color={'green'}
                        onClick={openCreateModal}>{t`Create Event`}
                    </Button>
                </>
            ) : undefined}
        />
    );
}
