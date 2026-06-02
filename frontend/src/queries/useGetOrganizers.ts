import {useQuery} from "@tanstack/react-query";
import {organizerClient} from "../api/organizer.client.ts";

export const GET_ORGANIZERS_QUERY_KEY = 'getOrganizers';

export const useGetOrganizers = (options?: { enabled?: boolean }) => {
    return useQuery({
        queryKey: [GET_ORGANIZERS_QUERY_KEY],
        enabled: options?.enabled ?? true,

        queryFn: async () => {
            return await organizerClient.all();
        }
    });
};