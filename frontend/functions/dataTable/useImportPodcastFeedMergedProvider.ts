import {
    DATATABLE_DEFAULT_CONTEXT,
    DataTableFilterContext,
    DataTableItemProvider,
} from "~/functions/useHasDatatable.ts";
import {useAxios} from "~/vendor/axios.ts";
import {ApiPodcastEpisode} from "~/entities/ApiInterfaces.ts";
import {computed, ref, shallowRef, toValue, watch, type MaybeRef} from "vue";
import {useQueryClient} from "@tanstack/vue-query";

/** Matches backend feed-items catalog shape (see PodcastRssFeedPanel). */
type FeedCatalogItem = {
    key: string;
    title: string;
    published_at: number;
    enclosure_url: string | null;
    enclosure_type: string | null;
    no_audio: boolean;
    imported: boolean;
    episode_id: string | null;
    has_media: boolean;
};

export type ImportPodcastEpisodeTableRow = ApiPodcastEpisode & {
    /** Row exists only in the RSS catalog; no DB episode yet (import from catalog to create). */
    _feedCatalogOnly?: boolean;
    _feedCatalogKey?: string;
};

function makeCatalogOnlyRow(item: FeedCatalogItem): ImportPodcastEpisodeTableRow {
    return {
        id: `__catalog__:${item.key}`,
        title: item.title,
        link: item.key,
        description: '',
        description_short: '',
        explicit: false,
        season_number: null,
        episode_number: null,
        created_at: item.published_at,
        publish_at: item.published_at,
        is_published: false,
        has_media: item.has_media,
        media: null,
        playlist_media: null,
        playlist_media_id: null,
        has_custom_art: false,
        art: null,
        art_updated_at: 0,
        links: {},
        _feedCatalogOnly: true,
        _feedCatalogKey: item.key,
    };
}

function mergeFeedItemsWithEpisodes(
    items: FeedCatalogItem[],
    episodes: ApiPodcastEpisode[]
): ImportPodcastEpisodeTableRow[] {
    const byId = new Map<string, ApiPodcastEpisode>();
    const byLink = new Map<string, ApiPodcastEpisode>();
    for (const ep of episodes) {
        if (ep.id) {
            byId.set(ep.id, ep);
        }
        if (ep.link) {
            byLink.set(ep.link, ep);
        }
    }

    const rows: ImportPodcastEpisodeTableRow[] = [];

    for (const item of items) {
        let ep: ApiPodcastEpisode | undefined;
        if (item.episode_id) {
            ep = byId.get(item.episode_id);
        }
        if (!ep) {
            ep = byLink.get(item.key);
        }

        if (ep?.id) {
            rows.push(ep as ImportPodcastEpisodeTableRow);
        } else {
            rows.push(makeCatalogOnlyRow(item));
        }
    }

    return rows;
}

function applySearch(rows: ImportPodcastEpisodeTableRow[], phrase: string): ImportPodcastEpisodeTableRow[] {
    const t = phrase.trim().toLowerCase();
    if (t === '') {
        return rows;
    }
    return rows.filter((r) => (r.title ?? '').toLowerCase().includes(t));
}

function applySort(
    rows: ImportPodcastEpisodeTableRow[],
    sortField: string | null,
    sortOrder: string | null
): ImportPodcastEpisodeTableRow[] {
    if (!sortField || !sortOrder) {
        return rows;
    }

    const mult = sortOrder === 'desc' ? -1 : 1;
    const copy = rows.slice();

    copy.sort((a, b) => {
        let va: string | number | boolean | null | undefined;
        let vb: string | number | boolean | null | undefined;
        switch (sortField) {
            case 'publish_at':
                va = a.publish_at ?? 0;
                vb = b.publish_at ?? 0;
                break;
            case 'explicit':
                va = a.explicit ? 1 : 0;
                vb = b.explicit ? 1 : 0;
                break;
            case 'season_number':
                va = a.season_number ?? -1;
                vb = b.season_number ?? -1;
                break;
            case 'episode_number':
                va = a.episode_number ?? -1;
                vb = b.episode_number ?? -1;
                break;
            default:
                return 0;
        }
        if (va === vb) {
            return 0;
        }
        return va < vb ? -mult : mult;
    });

    return copy;
}

function paginateSlice<Row>(
    rows: Row[],
    ctx: DataTableFilterContext
): Row[] {
    if (!ctx.paginated || ctx.perPage <= 0) {
        return rows;
    }
    const start = (ctx.currentPage - 1) * ctx.perPage;
    return rows.slice(start, start + ctx.perPage);
}

export function useImportPodcastFeedMergedProvider(
    episodesApiUrl: MaybeRef<string | null>,
    feedItemsApiUrl: MaybeRef<string | null>,
    queryKey: unknown[]
): DataTableItemProvider<ImportPodcastEpisodeTableRow> {
    const context = shallowRef<DataTableFilterContext>({
        ...DATATABLE_DEFAULT_CONTEXT,
        paginated: true,
        perPage: 10,
    });

    const rawMerged = shallowRef<ImportPodcastEpisodeTableRow[]>([]);
    const loading = ref(false);
    const {axios} = useAxios();
    const queryClient = useQueryClient();

    const setContext = (ctx: DataTableFilterContext) => {
        context.value = ctx;
    };

    const loadAll = async (): Promise<void> => {
        const epUrl = toValue(episodesApiUrl);
        const feedUrl = toValue(feedItemsApiUrl);
        if (!epUrl || !feedUrl) {
            rawMerged.value = [];
            return;
        }

        loading.value = true;
        try {
            const [feedRes, epRes] = await Promise.all([
                axios.get<{success: boolean; items?: FeedCatalogItem[]}>(feedUrl, {params: {internal: true}}),
                axios.get<{total: number; rows: ApiPodcastEpisode[]}>(epUrl, {
                    params: {
                        internal: true,
                        rowCount: 0,
                        current: 1,
                    },
                }),
            ]);

            const items = feedRes.data.success && Array.isArray(feedRes.data.items)
                ? feedRes.data.items
                : [];
            const episodeRows = epRes.data.rows ?? [];

            if (items.length > 0) {
                rawMerged.value = mergeFeedItemsWithEpisodes(items, episodeRows);
            } else {
                rawMerged.value = episodeRows.map((e) => e as ImportPodcastEpisodeTableRow);
            }
        } catch {
            rawMerged.value = [];
        } finally {
            loading.value = false;
        }
    };

    const filteredSorted = computed(() => {
        const ctx = context.value;
        let r = applySearch(rawMerged.value, ctx.searchPhrase);
        r = applySort(r, ctx.sortField, ctx.sortOrder);
        return r;
    });

    const total = computed(() => filteredSorted.value.length);

    const rows = computed(() => paginateSlice(filteredSorted.value, context.value));

    const refresh = async (): Promise<void> => {
        await queryClient.invalidateQueries({queryKey});
        await loadAll();
    };

    watch(
        () => [toValue(episodesApiUrl), toValue(feedItemsApiUrl)] as const,
        () => {
            void loadAll();
        },
        {immediate: true}
    );

    return {
        rows,
        total,
        loading: computed(() => loading.value),
        setContext,
        refresh,
    };
}

export function isImportEpisodeRowSelectable(row: ImportPodcastEpisodeTableRow): boolean {
    return !row._feedCatalogOnly && Boolean(row.links?.self);
}
