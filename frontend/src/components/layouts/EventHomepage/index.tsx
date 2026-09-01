import classes from "./EventHomepage.module.scss";
import SelectProducts from "../../routes/product-widget/SelectProducts";
import "../../../styles/widget/default.scss";
import React, {useEffect, useRef, useState} from "react";
import {EventDocumentHead} from "../../common/EventDocumentHead";
import {eventCoverImage, eventHomepageUrl, imageUrl, organizerHomepageUrl} from "../../../utilites/urlHelper.ts";
import {Event, OrganizerStatus} from "../../../types.ts";
import {EventNotAvailable} from "./EventNotAvailable";
import {
    IconArrowUpRight,
    IconCalendarPlus,
    IconMail,
    IconMapPin,
    IconShare,
    IconTicket,
    IconWorld
} from "@tabler/icons-react";
import {Anchor} from "@mantine/core";
import {t} from "@lingui/macro";
import {PoweredByFooter} from "../../common/PoweredByFooter";
import {ContactOrganizerModal} from "../../common/ContactOrganizerModal";
import {socialMediaConfig} from "../../../constants/socialMediaConfig";
import {
    formatAddress,
    getGoogleMapsUrl,
    getShortLocationDisplay,
    isAddressSet
} from "../../../utilites/addressUtilities.ts";
import {StatusToggle} from "../../common/StatusToggle";
import {getConfig} from "../../../utilites/config.ts";
import {useOrganizerTrackingPixels} from "../../../hooks/useOrganizerTrackingPixels";
import {trackPixelEvent, hasActivePixels} from "../../../utilites/trackingPixels";
import {CookieConsentBanner} from "../../common/CookieConsentBanner";
import {ShareComponent} from "../../common/ShareIcon";
import {EventDateRange} from "../../common/EventDateRange";
import {CalendarOptionsPopover} from "../../common/CalendarOptionsPopover";
import {isDateInPast} from "../../../utilites/dates.ts";

// Future Shopify merch lives on this same Kamper page: one coherent surface for tickets,
// event information, and later merchandise. The section, its Kamp Love styling, and its mount
// slot exist and stay off until Shopify is connected. Nothing here calls a storefront.
const SHOW_MERCH_SECTION = false;

// "Your Kamp" prep modules (what to bring, schedule). These render as honest, clearly-empty
// future insertion points — no fabricated event content. Set false to hide them entirely.
const SHOW_KAMP_PREP = true;

interface EventHomepageProps {
    event?: Event;
    promoCodeValid?: boolean;
    promoCode?: string;
}

const EventHomepage = ({...loaderData}: EventHomepageProps) => {
    const {event, promoCodeValid, promoCode} = loaderData;
    const [showScrollButton, setShowScrollButton] = useState(false);
    const [contactModalOpen, setContactModalOpen] = useState(false);
    const ticketsSectionRef = useRef<HTMLDivElement>(null);

    const {consentPending, consentGranted, onConsent} = useOrganizerTrackingPixels(
        event?.organizer?.settings?.tracking_pixels
    );

    useEffect(() => {
        if (event && consentGranted && hasActivePixels()) {
            trackPixelEvent({
                eventName: 'ViewContent',
                contentName: event.title,
                contentId: event.id,
            });
        }
    }, [event?.id, consentGranted]);

    useEffect(() => {
        let showTimer: NodeJS.Timeout;

        const checkTicketsPosition = () => {
            if (ticketsSectionRef.current) {
                const rect = ticketsSectionRef.current.getBoundingClientRect();
                const isBelowFold = rect.top > window.innerHeight;
                const isAboveView = rect.bottom < 0;
                const shouldShowButton = isBelowFold || isAboveView;
                setShowScrollButton(shouldShowButton);
            }
        };

        showTimer = setTimeout(() => {
            checkTicketsPosition();
        }, 500);

        const handleScroll = () => {
            checkTicketsPosition();
        };

        const handleResize = () => {
            checkTicketsPosition();
        };

        window.addEventListener('scroll', handleScroll);
        window.addEventListener('resize', handleResize);

        return () => {
            clearTimeout(showTimer);
            window.removeEventListener('scroll', handleScroll);
            window.removeEventListener('resize', handleResize);
        };
    }, []);

    const scrollToTickets = () => {
        ticketsSectionRef.current?.scrollIntoView({behavior: 'smooth', block: 'start'});
    };

    if (!event) {
        return <EventNotAvailable/>;
    }

    const themeStyles = {
        '--event-bg-color': '#f9f4f0',
        '--event-content-bg-color': '#ffffff',
        '--event-primary-color': '#171717',
        '--event-primary-text-color': '#171717',
        '--event-secondary-color': '#585254',
        '--event-secondary-text-color': '#585254',
        '--event-accent-contrast': '#f9f4f0',
        '--event-accent-soft': '#fdeede',
        '--event-accent-muted': '#ff7b00',
        '--event-border-color': '#dadada',
        '--theme-font-family': "'PT Serif', Georgia, 'Times New Roman', serif",
        fontFamily: "'PT Serif', Georgia, 'Times New Roman', serif",
    } as React.CSSProperties;

    const coverImageData = eventCoverImage(event);
    const coverImage = coverImageData?.url;
    const organizer = event.organizer!;
    const organizerSocials = organizer?.settings?.social_media_handles;
    const organizerLogo = imageUrl('ORGANIZER_LOGO', organizer?.images);
    const organizerLocation = organizer?.settings?.location_details;
    const websiteUrl = organizer?.website;
    const locationDetails = event.settings?.location_details;
    const isOnlineEvent = event.settings?.is_online_event;
    const hasLocation = isAddressSet(locationDetails) && !isOnlineEvent;

    const socialLinks = organizerSocials ? Object.entries(organizerSocials)
        .filter(([platform, handle]) => handle && socialMediaConfig[platform as keyof typeof socialMediaConfig])
        .map(([platform, handle]) => ({
            platform,
            handle: handle as string,
            config: socialMediaConfig[platform as keyof typeof socialMediaConfig]
        })) : [];

    const getStatusBadge = () => {
        const products = event.products || event.product_categories?.flatMap(c => c.products || []) || [];

        if (products.length === 0) {
            return null;
        }

        const availableProducts = products.filter(p => p.is_available && !p.is_sold_out);
        const allSoldOut = products.every(p => p.is_sold_out);

        if (allSoldOut) {
            return {text: t`Sold Out`, variant: 'danger'};
        }

        if (availableProducts.length === 0) {
            return null;
        }

        return {text: t`Tickets Available`, variant: 'success'};
    };

    const statusBadge = getStatusBadge();

    const heroKicker = (hasLocation && locationDetails)
        ? (getShortLocationDisplay(locationDetails) || locationDetails.venue_name || organizer?.name)
        : (isOnlineEvent ? t`Online Event` : organizer?.name);

    const mapUrl = event.settings?.maps_url || (locationDetails ? getGoogleMapsUrl(locationDetails) : null);

    return (
        <>
            {event?.status && event?.id && (
                <StatusToggle
                    entityType="event"
                    entityId={event.id}
                    currentStatus={event.status as 'DRAFT' | 'LIVE'}
                    entityName={event.title}
                    onSuccess={() =>
                        setTimeout(() => {
                            window.location.reload();
                        }, 1000)}
                />
            )}

            <main
                className={classes.pageWrapper}
                style={themeStyles}
                data-mode="light"
            >
                <style>
                    {`
                        body, .ssr-loader {
                            background-color: #f9f4f0 !important;
                        }
                    `}
                </style>

                {event && <EventDocumentHead event={event}/>}

                {/* Kamp Love ground: a calm cream field, never a blurred cover mirror */}
                <div className={classes.background}/>

                <div className={classes.container}>
                    <div className={classes.wrapper}>
                        {/* Main unified card */}
                        <div className={classes.mainCard}>
                            {/* Hero Section */}
                            <div className={classes.heroSection}>
                                {coverImage && (
                                    <div
                                        className={classes.coverWrapper}
                                        style={(coverImageData?.width && coverImageData?.height) ? {
                                            '--cover-aspect-ratio': `${coverImageData.width} / ${coverImageData.height}`,
                                        } as React.CSSProperties : undefined}
                                    >
                                        {coverImageData?.lqip_base64 && (
                                            <img
                                                src={coverImageData.lqip_base64}
                                                alt=""
                                                aria-hidden="true"
                                                className={classes.coverLqip}
                                            />
                                        )}
                                        <img
                                            src={coverImage}
                                            alt={event.title}
                                            className={classes.coverImage}
                                        />
                                        <div className={classes.heroGradient}/>
                                        <div className={classes.heroOverlay}>
                                            {statusBadge && (
                                                <div className={classes.statusBadges}>
                                                    <span className={classes.statusBadge}>
                                                        <IconTicket/>
                                                        {statusBadge.text}
                                                    </span>
                                                </div>
                                            )}
                                            {heroKicker && (
                                                <div className={classes.heroKicker}>
                                                    {heroKicker}
                                                </div>
                                            )}
                                            <h1 className={classes.eventTitle}>{event.title}</h1>
                                        </div>
                                    </div>
                                )}

                            </div>

                            {/* Byline: who is inviting you to this Kamp */}
                            <div className={classes.bylineStrip}>
                                {organizer && organizer.status === OrganizerStatus.LIVE ? (
                                    <a
                                        href={organizerHomepageUrl(organizer)}
                                        className={classes.organizerPill}
                                    >
                                        {organizerLogo ? (
                                            <img
                                                src={organizerLogo}
                                                alt={organizer.name}
                                                className={classes.organizerPillAvatar}
                                            />
                                        ) : (
                                            <span className={classes.organizerPillAvatarPlaceholder}>
                                                {organizer.name.charAt(0).toUpperCase()}
                                            </span>
                                        )}
                                        <span className={classes.organizerPillName}>
                                            {organizer.name}
                                        </span>
                                    </a>
                                ) : (
                                    <div className={classes.organizerPill}>
                                        {organizerLogo ? (
                                            <img
                                                src={organizerLogo}
                                                alt={organizer?.name || ''}
                                                className={classes.organizerPillAvatar}
                                            />
                                        ) : (
                                            <span className={classes.organizerPillAvatarPlaceholder}>
                                                {organizer?.name?.charAt(0).toUpperCase() || '?'}
                                            </span>
                                        )}
                                        <span className={classes.organizerPillName}>
                                            {organizer?.name}
                                        </span>
                                    </div>
                                )}

                                <div className={classes.actionButtons}>
                                    <ShareComponent
                                        title={'Check out this event: ' + event.title}
                                        text={'Check out this event: ' + event.title}
                                        url={eventHomepageUrl(event)}
                                        imageUrl={coverImage || undefined}
                                    >
                                        <button className={classes.actionButton} title={t`Share`}>
                                            <IconShare/>
                                        </button>
                                    </ShareComponent>
                                </div>
                            </div>

                            {!coverImage && (
                                <div className={classes.section}>
                                    {heroKicker && (
                                        <div className={classes.eyebrow}>{heroKicker}</div>
                                    )}
                                    <h1 className={classes.eventTitle}>{event.title}</h1>
                                </div>
                            )}

                            {/* Essentials: logistics made delightfully simple */}
                            <div className={classes.essentialsBand}>
                                <div className={classes.essentialsGrid}>
                                    <div className={classes.essentialBlock}>
                                        <div className={classes.essentialLabel}>{t`When`}</div>
                                        <div className={classes.essentialValue}>
                                            <EventDateRange event={event}/>
                                        </div>
                                        {event.end_date && isDateInPast(event.end_date) && (
                                            <div className={classes.essentialSub}>{t`This event has ended`}</div>
                                        )}
                                    </div>

                                    {isOnlineEvent && (
                                        <div className={classes.essentialBlock}>
                                            <div className={classes.essentialLabel}>{t`Where`}</div>
                                            <div className={classes.essentialValue}>{t`Online Event`}</div>
                                            <div className={classes.essentialSub}>{t`Join from anywhere`}</div>
                                        </div>
                                    )}

                                    {hasLocation && locationDetails && (
                                        <div className={classes.essentialBlock}>
                                            <div className={classes.essentialLabel}>{t`Where`}</div>
                                            <div className={classes.essentialValue}>{locationDetails.venue_name}</div>
                                            <div className={classes.essentialSub}>
                                                <IconMapPin/>
                                                {getShortLocationDisplay(locationDetails) || formatAddress(locationDetails)}
                                            </div>
                                        </div>
                                    )}
                                </div>

                                <div className={classes.essentialActions}>
                                    <CalendarOptionsPopover event={event}>
                                        <button className={classes.lightPill}>
                                            <IconCalendarPlus/>
                                            {t`Add to Calendar`}
                                        </button>
                                    </CalendarOptionsPopover>
                                    {mapUrl && (
                                        <a
                                            href={mapUrl}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            className={`${classes.lightPill} ${classes.ghostPill}`}
                                        >
                                            <IconArrowUpRight/>
                                            {t`Get Directions`}
                                        </a>
                                    )}
                                </div>
                            </div>

                            {/* About Section */}
                            {event?.description && (
                                <div className={classes.section}>
                                    <div className={classes.sectionHeader}>
                                        <h2 className={classes.sectionTitle}>{t`About`}</h2>
                                    </div>
                                    <div
                                        className={classes.description}
                                        dangerouslySetInnerHTML={{__html: event.description}}
                                    />
                                </div>
                            )}

                            {/* Tickets: one substantial cream panel, part of the Kamp invitation */}
                            <div className={classes.ticketsBand} id="tickets" ref={ticketsSectionRef}>
                                <div className={classes.ticketsPanel}>
                                    <div className={classes.ticketsEyebrow}>{t`Registration`}</div>
                                    <h2 className={classes.ticketsTitle}>{t`Join us at Kamp`}</h2>
                                    <p className={classes.ticketsIntro}>
                                        {t`Choose your registration below.`}
                                    </p>
                                    <div className={classes.ticketsSection}>
                                        <SelectProducts
                                            colors={{
                                                background: "transparent",
                                                primary: "var(--event-primary-color)",
                                                primaryText: "var(--event-primary-text-color)",
                                                secondary: "var(--event-primary-color)",
                                                secondaryText: "var(--event-accent-contrast)",
                                                bodyBackground: "var(--event-bg-color)",
                                            }}
                                            continueButtonText={event.settings?.continue_button_text}
                                            padding={"0px"}
                                            event={event}
                                            promoCodeValid={promoCodeValid}
                                            promoCode={promoCode}
                                            showPoweredBy={false}
                                        />
                                    </div>
                                </div>
                            </div>

                            {/* Your Kamp: logistics expressed visually. Real data populates cards;
                                modules without a data source are honest, clearly-empty insertion points. */}
                            {(hasLocation || SHOW_KAMP_PREP) && (
                                <div className={classes.yourKampBand} id="your-kamp">
                                    <div className={classes.sectionHeader}>
                                        <div className={classes.eyebrow}>{t`Your Kamp`}</div>
                                        <h2 className={classes.sectionTitle}>{t`Getting ready`}</h2>
                                    </div>
                                    <div className={classes.logisticsGrid}>
                                        {hasLocation && locationDetails && (
                                            <div className={classes.logisticsCard}>
                                                <div className={classes.logisticsLabel}>{t`Getting there`}</div>
                                                <div className={classes.logisticsValue}>{locationDetails.venue_name}</div>
                                                <div className={classes.logisticsBody}>{formatAddress(locationDetails)}</div>
                                                {mapUrl && (
                                                    <a
                                                        href={mapUrl}
                                                        target="_blank"
                                                        rel="noopener noreferrer"
                                                        className={classes.logisticsLink}
                                                    >
                                                        <IconMapPin/>
                                                        {t`Get Directions`}
                                                    </a>
                                                )}
                                            </div>
                                        )}

                                        {SHOW_KAMP_PREP && (
                                            <div className={`${classes.logisticsCard} ${classes.logisticsCardPending}`}>
                                                <div className={classes.logisticsLabel}>{t`What to bring`}</div>
                                                <div className={classes.logisticsPending}>{t`A packing list will appear here when it's posted.`}</div>
                                            </div>
                                        )}

                                        {SHOW_KAMP_PREP && (
                                            <div className={`${classes.logisticsCard} ${classes.logisticsCardPending}`}>
                                                <div className={classes.logisticsLabel}>{t`Schedule`}</div>
                                                <div className={classes.logisticsPending}>{t`The schedule will appear here when it's posted.`}</div>
                                            </div>
                                        )}
                                    </div>
                                </div>
                            )}

                            {/* Merch: an integrated editorial storefront insertion point (not connected) */}
                            {SHOW_MERCH_SECTION && (
                                <section className={classes.merchBand} id="merch" aria-label={t`Merch`}>
                                    <div className={classes.sectionHeader}>
                                        <div className={classes.eyebrow}>{t`Kamp Store`}</div>
                                        <h2 className={classes.sectionTitle}>{t`Take Kamp home`}</h2>
                                    </div>
                                    <p className={classes.faqNote}>
                                        {t`Kamp Love merch will live here, on the same page as your ticket.`}
                                    </p>
                                    {/*
                                      Shopify Buy Button mount point. To connect later: load Shopify's
                                      buy-button.js storefront SDK once, initialize a ShopifyBuy.UI client with
                                      the shop domain and Storefront access token, then render the collection into
                                      the slot below. No storefront request is made until that wiring exists.
                                    */}
                                    <div className={classes.merchSlot} data-shopify-merch-slot>
                                        {t`Merch storefront mounts here once connected.`}
                                    </div>
                                </section>
                            )}

                            {/* Questions: an honest FAQ-ready module backed by the real organizer contact */}
                            {organizer && (
                                <section className={classes.faqBand} id="questions" aria-label={t`Questions`}>
                                    <div className={classes.sectionHeader}>
                                        <div className={classes.eyebrow}>{t`Questions`}</div>
                                        <h2 className={classes.sectionTitle}>{t`Questions about this Kamp?`}</h2>
                                    </div>
                                    <p className={classes.faqNote}>
                                        {t`Reach out and we'll help you get ready.`}
                                    </p>
                                    <button
                                        onClick={() => setContactModalOpen(true)}
                                        className={classes.contactButton}
                                    >
                                        <IconMail/>
                                        {t`Contact`}
                                    </button>
                                </section>
                            )}

                            {/* Organizer Section */}
                            {organizer && organizer.status === OrganizerStatus.LIVE && (
                                <div className={classes.section} id="organizer">
                                    <div className={classes.sectionHeader}>
                                        <h2 className={classes.sectionTitle}>{t`Organizer`}</h2>
                                    </div>
                                    <div className={classes.organizerCard}>
                                        {organizerLogo ? (
                                            <img
                                                src={organizerLogo}
                                                alt={organizer.name}
                                                className={classes.organizerAvatar}
                                            />
                                        ) : (
                                            <div className={classes.organizerAvatarPlaceholder}>
                                                {organizer.name.charAt(0).toUpperCase()}
                                            </div>
                                        )}
                                        <div className={classes.organizerContent}>
                                            <div className={classes.organizerHeader}>
                                                <div>
                                                    <h3 className={classes.organizerName}>
                                                        <Anchor href={organizerHomepageUrl(organizer)}>
                                                            {organizer.name}
                                                        </Anchor>
                                                    </h3>
                                                    {getShortLocationDisplay(organizerLocation) && (
                                                        <div className={classes.organizerLocation}>
                                                            <IconMapPin/>
                                                            <a
                                                                href={getGoogleMapsUrl(organizerLocation!)}
                                                                target="_blank"
                                                                rel="noopener noreferrer"
                                                            >
                                                                {getShortLocationDisplay(organizerLocation)}
                                                            </a>
                                                        </div>
                                                    )}
                                                </div>
                                            </div>

                                            {organizer.description && (
                                                <div
                                                    className={classes.organizerBio}
                                                    dangerouslySetInnerHTML={{__html: organizer.description}}
                                                />
                                            )}

                                            <div className={classes.organizerActions}>
                                                {socialLinks.length > 0 && (
                                                    <div className={classes.socialLinks}>
                                                        {socialLinks.map(({platform, handle, config}) => {
                                                            const IconComponent = config.icon;
                                                            const url = config.baseUrl + handle;
                                                            return (
                                                                <a
                                                                    key={platform}
                                                                    href={url}
                                                                    target="_blank"
                                                                    rel="noopener noreferrer"
                                                                    className={classes.socialLink}
                                                                    title={platform}
                                                                >
                                                                    <IconComponent size={18}/>
                                                                </a>
                                                            );
                                                        })}
                                                    </div>
                                                )}
                                                {websiteUrl && (() => {
                                                    try {
                                                        const hostname = new URL(websiteUrl).hostname;
                                                        return (
                                                            <a
                                                                href={websiteUrl}
                                                                target="_blank"
                                                                rel="noopener noreferrer"
                                                                className={classes.socialLink}
                                                                title={hostname}
                                                            >
                                                                <IconWorld size={18}/>
                                                            </a>
                                                        );
                                                    } catch {
                                                        return null;
                                                    }
                                                })()}
                                                <button
                                                    onClick={() => setContactModalOpen(true)}
                                                    className={classes.contactButton}
                                                >
                                                    <IconMail/>
                                                    {t`Contact`}
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            )}

                            {/* Kamp Love signature: Camping, Connection, Community, Christ */}
                            <div className={classes.signatureBand} aria-label={t`Kamp Love`}>
                                <span className={classes.signatureItem}>{t`Camping`}</span>
                                <span className={classes.signatureItem}>{t`Connection`}</span>
                                <span className={classes.signatureItem}>{t`Community`}</span>
                                <span className={classes.signatureItem}>{t`Christ`}</span>
                            </div>
                        </div>

                        {/* Footer */}
                        <div className={classes.footerSection}>
                            <div className={classes.footerLinks}>
                                <Anchor
                                    href={getConfig('VITE_PRIVACY_URL', 'https://kamplove.org/privacy-policy')}
                                    className={classes.footerLink}
                                >
                                    {t`Privacy Policy`}
                                </Anchor>
                                <Anchor
                                    href={getConfig('VITE_TOS_URL', 'https://kamplove.org/terms-conditions')}
                                    className={classes.footerLink}
                                >
                                    {t`Terms of Service`}
                                </Anchor>
                            </div>
                            <PoweredByFooter className={classes.poweredByFooter}/>
                        </div>
                    </div>

                    {/* Floating Scroll Button */}
                    {showScrollButton && (
                        <button
                            className={classes.scrollToTicketsButton}
                            onClick={scrollToTickets}
                        >
                            <IconTicket size={18}/>
                            {t`Register`}
                        </button>
                    )}

                    {/* Contact Modal */}
                    <ContactOrganizerModal
                        opened={contactModalOpen}
                        onClose={() => setContactModalOpen(false)}
                        organizer={organizer}
                    />
                </div>
                {consentPending && (
                    <CookieConsentBanner onConsent={onConsent}/>
                )}
            </main>
        </>
    );
};

export default EventHomepage;
