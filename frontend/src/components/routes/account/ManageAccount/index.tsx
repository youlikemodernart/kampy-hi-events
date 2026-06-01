import {Card} from "../../../common/Card";
import {Tabs} from "@mantine/core";
import classes from "./ManageAccount.module.scss";
import {IconAdjustmentsCog, IconCreditCard, IconReceiptTax, IconUsers} from "@tabler/icons-react";
import {Outlet, useLocation, useNavigate} from "react-router";
import {t} from "@lingui/macro";
import {useCurrentUserCan} from "../../../../hooks/useIsCurrentUserAdmin.ts";
import { useGetAccount } from "../../../../queries/useGetAccount.ts";

export const ManageAccount = () => {
    const navigate = useNavigate();
    const location = useLocation();
    const tabValue = location.pathname.split('/').pop() || 'settings';
    const canManageAccount = useCurrentUserCan('account.manage');
    const canManageTeam = useCurrentUserCan('team.manage');
    const canManageBilling = useCurrentUserCan('billing.manage');
    const {data: account} = useGetAccount();

    return (
        <div className={classes.container}>
            <h1>{t`Account Settings`}</h1>
            <Card className={classes.tabsCard}>
                <Tabs value={tabValue} onChange={(value) => navigate(`/account/${value}`)}>
                    <Tabs.List grow>
                        {canManageAccount && (
                            <Tabs.Tab value="settings" leftSection={<IconAdjustmentsCog/>}>
                                {t`Account`}
                            </Tabs.Tab>
                        )}
                        {canManageBilling && (
                            <Tabs.Tab value="taxes-and-fees" leftSection={<IconReceiptTax/>}>
                                {t`Tax & Fees`}
                            </Tabs.Tab>
                        )}

                        {canManageTeam && (
                            <Tabs.Tab value="users" leftSection={<IconUsers/>}>
                                {t`Users`}
                            </Tabs.Tab>
                        )}

                        {(canManageBilling && account && account.is_saas_mode_enabled) && (
                            <Tabs.Tab value="payment" leftSection={<IconCreditCard/>}>
                                {t`Payment & Plan`}
                            </Tabs.Tab>
                        )}
                    </Tabs.List>
                </Tabs>
            </Card>
            <div className={classes.tabWrapper}>
                <Outlet/>
            </div>
        </div>
    );
};

export default ManageAccount;
