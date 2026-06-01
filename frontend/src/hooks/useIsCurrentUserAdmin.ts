import {useGetMe} from "../queries/useGetMe.ts";
import {Permission} from "../types.ts";

export const useCurrentUserCan = (permission: Permission) => {
    const {data: user, isFetched} = useGetMe();
    return isFetched && (user?.permissions?.includes(permission) || false);
}

export const currentUserCan = (permissions: Permission[] | undefined, permission: Permission) => {
    return permissions?.includes(permission) || false;
}

export const useIsCurrentUserAdmin = () => {
    return useCurrentUserCan('team.manage');
}

export const useIsCurrentUserSuperAdmin = () => {
    const {data: user, isFetched} = useGetMe();
    return isFetched && user?.role === 'SUPERADMIN';
}
