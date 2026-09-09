import {readFileSync} from 'node:fs';
import {dirname, join} from 'node:path';
import {fileURLToPath} from 'node:url';
import {describe, expect, it} from 'vitest';

const here = dirname(fileURLToPath(import.meta.url));
const ordersTable = readFileSync(join(here, '../components/common/OrdersTable/index.tsx'), 'utf8');
const manageOrderModal = readFileSync(join(here, '../components/modals/ManageOrderModal/index.tsx'), 'utf8');
const ordersPage = readFileSync(join(here, '../components/routes/event/orders.tsx'), 'utf8');
const questionAnswers = readFileSync(join(here, '../components/common/QuestionAndAnswerList/index.tsx'), 'utf8');
const attendeeList = readFileSync(join(here, '../components/common/AttendeeList/index.tsx'), 'utf8');

describe('order operations permission projection', () => {
    it('keeps order-management and refund controls behind their backend permissions', () => {
        expect(ordersTable).toContain("useCurrentUserCan('orders.manage')");
        expect(ordersTable).toContain("useCurrentUserCan('orders.refund')");
        expect(ordersTable).toMatch(/canManageOrders && order\.status === 'AWAITING_OFFLINE_PAYMENT'/);
        expect(ordersTable).toMatch(/canRefundOrders && isRefundable/);
        expect(ordersTable).toMatch(/canManageOrders && order\.status === 'COMPLETED'/);
        expect(ordersTable).toMatch(/canManageOrders && order\.status !== 'CANCELLED'/);
    });

    it('uses the message permission for every messaging entry point and refreshes memoized columns', () => {
        expect(ordersTable).toContain("useCurrentUserCan('messages.manage')");
        expect(ordersTable.match(/canManageMessages && \(/g)).toHaveLength(2);
        expect(ordersTable).toContain('[event.id, emailPopoverId, canManageOrders, canRefundOrders, canManageMessages]');
    });

    it('keeps order and answer edits behind their distinct backend permissions', () => {
        expect(manageOrderModal).toContain("useCurrentUserCan('orders.manage')");
        expect(manageOrderModal).toContain("useCurrentUserCan('event.content.manage')");
        expect(manageOrderModal).toMatch(/canManageOrders && \(\s*<Tabs\.Tab value="edit"/);
        expect(manageOrderModal).toMatch(/canManageOrders && \(\s*<Tabs\.Panel value="edit"/);
        expect(manageOrderModal.match(/canEditAnswers=\{canManageEventContent\}/g)).toHaveLength(2);
        expect(questionAnswers).toMatch(/canEditAnswer && \(\s*<Tooltip label=\{t`Edit Answer`\}/);
        expect(attendeeList).toContain('canEditAnswers={canEditAnswers}');
    });

    it('shows export only to users with reports.export', () => {
        expect(ordersPage).toContain("useCurrentUserCan('reports.export')");
        expect(ordersPage).toMatch(/canExportOrders && \(\s*<Button/);
    });
});
