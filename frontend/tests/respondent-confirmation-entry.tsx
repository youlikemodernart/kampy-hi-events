import React from 'react';
import {createRoot} from 'react-dom/client';
import {MantineProvider} from '@mantine/core';
import {QueryClient, QueryClientProvider} from '@tanstack/react-query';
import {I18nProvider} from '@lingui/react';
import {i18n} from '@lingui/core';
import {messages} from '../src/locales/en.po';
import '@mantine/core/styles.css';
import {RespondentConfirmationPanel} from '../src/components/routes/product-widget/OrderSummaryAndProducts/RespondentConfirmationPanel';
import {Order} from '../src/types';

i18n.load('en', messages); i18n.activate('en');
window.hievents = {...window.hievents, VITE_API_URL_CLIENT: window.location.origin};
const id = new URLSearchParams(window.location.search).get('historical') ? 12 : 11;
const order = {short_id: `order_${id}`, attendees: [1, 2].map(n => ({id: id * 10 + n, status: 'ACTIVE', first_name: 'Invented', last_name: `Attendee ${n}`}))} as Order;
createRoot(document.getElementById('root')!).render(<I18nProvider i18n={i18n}><QueryClientProvider client={new QueryClient()}><MantineProvider><main style={{maxWidth: 600, margin: '16px auto', padding: 12}}><RespondentConfirmationPanel order={order}/></main></MantineProvider></QueryClientProvider></I18nProvider>);
