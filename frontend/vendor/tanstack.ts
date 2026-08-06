import {VueQueryPlugin, VueQueryPluginOptions} from "@tanstack/vue-query";
import {App} from "vue";

const vueQueryPluginOptions: VueQueryPluginOptions = {
    enableDevtoolsV6Plugin: true,
    queryClientConfig: {
        defaultOptions: {
            queries: {
                retryDelay: (attemptIndex) => Math.min(2500 * 2 ** attemptIndex, 30000),
                // Every query in this app already refreshes itself via an explicit
                // refetchInterval or an explicit invalidateQueries() after a mutation.
                // Also refetching on window/tab focus is a second, redundant trigger that
                // fires the instant a backgrounded mobile tab is foregrounded again — often
                // before the device's network has actually reconnected — causing every
                // active query to fail and retry at once. Removing it removes that race
                // entirely rather than papering over its symptoms.
                refetchOnWindowFocus: false,
            },
        },
    },
}

export default function installTanstack(vueApp: App) {
    vueApp.use(VueQueryPlugin, vueQueryPluginOptions)
}

