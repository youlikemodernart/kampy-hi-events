import React from 'react';
import {createRoot} from 'react-dom/client';
import {MantineProvider} from '@mantine/core';
import {Notifications} from '@mantine/notifications';
import {QueryClient, QueryClientProvider} from '@tanstack/react-query';
import {I18nProvider} from '@lingui/react';
import {i18n} from '@lingui/core';
import {HelmetProvider} from 'react-helmet-async';
import {createBrowserRouter, RouterProvider} from 'react-router';
import {messages} from '../src/locales/en.po';
import '@mantine/core/styles.css';
import '../src/styles/global.scss';
import '@mantine/notifications/styles.css';
import Checkout from '../src/components/layouts/Checkout';
import {CollectInformation} from '../src/components/routes/product-widget/CollectInformation';
import {OrderSummaryAndProducts} from '../src/components/routes/product-widget/OrderSummaryAndProducts';

i18n.load('en', messages); i18n.activate('en');
window.hievents = {...window.hievents, VITE_API_URL_CLIENT: window.location.origin};
const router = createBrowserRouter([{path: '/checkout/:eventId/:orderShortId', element: <Checkout/>, children: [
    {path: 'details', element: <CollectInformation/>},
    {path: 'payment', element: <p>Synthetic payment boundary — no provider connected</p>},
    {path: 'summary', element: <OrderSummaryAndProducts/>},
]}]);
createRoot(document.getElementById('root')!).render(<HelmetProvider><I18nProvider i18n={i18n}><QueryClientProvider client={new QueryClient()}><MantineProvider><Notifications/><RouterProvider router={router}/></MantineProvider></QueryClientProvider></I18nProvider></HelmetProvider>);
