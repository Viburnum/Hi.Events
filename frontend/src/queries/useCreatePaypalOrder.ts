import {useQuery} from "@tanstack/react-query";
import {orderClientPublic} from "../api/order.client.ts";
import {IdParam} from "../types.ts";

export const GET_INITIATE_PAYPAL_ORDER_PUBLIC_QUERY_KEY = 'getPaypalOrderPublic';

export const useCreatePaypalOrder = (eventId: IdParam, orderShortId: IdParam, enabled = true) => {
    return useQuery({
        queryKey: [GET_INITIATE_PAYPAL_ORDER_PUBLIC_QUERY_KEY],

        queryFn: async () => {
            const {paypal_order_id, client_id, currency} = await orderClientPublic.createPaypalOrder(
                Number(eventId),
                String(orderShortId),
            );
            return {paypal_order_id, client_id, currency};
        },

        enabled,
        retry: false,
        staleTime: 0,
        gcTime: 0
    });
}
