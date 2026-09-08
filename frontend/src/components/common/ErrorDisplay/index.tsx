import {t} from '@lingui/macro';
import {Box, Button, Container, Stack, Text, Title} from '@mantine/core';
import {IconHome} from '@tabler/icons-react';
import classes from './ErrorDisplay.module.scss';
import {Helmet} from "react-helmet-async";
import {useRouteError} from "react-router";
import {PoweredByFooter} from "../PoweredByFooter";
import {BrandMark} from "../BrandMark";
import {appName, brandSiteUrl} from '../../../utilites/branding';

export const ErrorDisplay = () => {
    const error = useRouteError() as any;

    const title = error?.status === 404
        ? t`Page not found`
        : t`Something went wrong`;

    const description = error?.status === 404
        ? t`The page you are looking for does not exist`
        : t`An error occurred while loading the page`;

    return (
        <>
            <Helmet
                title={`${title} | ${appName()}`}
                meta={[
                    {
                        name: 'description',
                        content: description,
                    },
                    {
                        name: 'robots',
                        content: 'noindex, nofollow',
                    },
                ]}
            />
            <Box className={classes.wrapper}>
                <Container size="md" className={classes.root}>
                    <Stack gap="xl" align="center">
                        <BrandMark className={classes.logo}/>

                        <Stack gap="lg" align="center" className={classes.content}>
                            <Title order={1} className={classes.title}>
                                {title}
                            </Title>

                            <Text size="lg" c="dimmed" className={classes.description}>
                                {description}
                            </Text>
                            {/*
                              Sends the buyer to the Kamp Love site. The previous target
                              was "/", which the root route resolves to /auth/login for
                              anyone not signed in, i.e. the staff login screen.
                            */}
                            <Button
                                component="a"
                                href={brandSiteUrl()}
                                leftSection={<IconHome size={18}/>}
                                className={classes.button}
                            >
                                {t`Go to Kamp Love`}
                            </Button>
                        </Stack>

                        <PoweredByFooter/>
                    </Stack>
                </Container>
            </Box>
        </>
    );
};

export default ErrorDisplay;
