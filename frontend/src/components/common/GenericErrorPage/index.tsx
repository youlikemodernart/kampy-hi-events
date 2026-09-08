import React from 'react';
import {Box, Button, Container, Stack, Text, Title} from '@mantine/core';
import {IconHome} from '@tabler/icons-react';
import classes from './GenericErrorPage.module.scss';
import {PoweredByFooter} from "../PoweredByFooter";
import {Helmet} from "react-helmet-async";
import {BrandMark} from "../BrandMark";
import {appName} from "../../../utilites/branding.ts";

interface GenericErrorPageProps {
    title: string;
    description: string;
    pageTitle?: string;
    metaDescription?: string;
    buttonText?: string;
    buttonUrl?: string;
    buttonIcon?: React.ReactNode;
    children?: React.ReactNode;
}

export const GenericErrorPage: React.FC<GenericErrorPageProps> = ({
                                                                      title,
                                                                      description,
                                                                      pageTitle,
                                                                      metaDescription,
                                                                      buttonText,
                                                                      buttonUrl,
                                                                      buttonIcon = <IconHome size={18}/>,
                                                                      children
                                                                  }) => {
    return (
        <>
            <Helmet
                title={`${pageTitle || title} | ${appName()}`}
                meta={[
                    {
                        name: 'description',
                        content: metaDescription || description,
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

                            {children}

                            {buttonText && buttonUrl && (
                                <Button
                                    component="a"
                                    href={buttonUrl}
                                    leftSection={buttonIcon}
                                    className={classes.button}
                                >
                                    {buttonText}
                                </Button>
                            )}
                        </Stack>

                        <PoweredByFooter/>
                    </Stack>
                </Container>
            </Box>
        </>
    );
};

export default GenericErrorPage;
