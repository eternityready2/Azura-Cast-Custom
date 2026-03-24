import {useAppRegle} from "~/vendor/regle.ts";
import {useResettableRef} from "~/functions/useResettableRef.ts";
import {defineStore} from "pinia";
import {createRule} from "@regle/core";
import {required} from "@regle/rules";
import {ref, watch} from "vue";
import {useTranslate} from "~/vendor/gettext.ts";
import {PodcastExtraData, PodcastRecord} from "~/entities/Podcasts.ts";

export type RssBackgroundSyncMode = 'every' | 'before_air';

export const useStationsPodcastsForm = defineStore(
    'form-stations-podcasts',
    () => {
        const {record, reset: resetRecord} = useResettableRef<PodcastExtraData>({
            has_custom_art: false,
            art: '',
            links: {
                art: ''
            }
        });

        const {record: form, reset} = useResettableRef<PodcastRecord>({
            title: '',
            link: '',
            description: '',
            language: 'en',
            author: '',
            email: '',
            categories: [],
            is_enabled: true,
            explicit: false,
            branding_config: {
                public_custom_html: '',
                enable_op3_prefix: false
            },
            source: 'manual',
            playlist_id: null,
            playlist_auto_publish: true,
            feed_url: '',
            auto_import_enabled: true,
            auto_keep_episodes: 1,
            import_strategy: 'backfill_all' as const,
            import_sync_before_hours: 5,
            episode_storage_type: 'media',
            media_folder_path: '',
            artwork_file: null,
        });

        const rssBackgroundSyncMode = ref<RssBackgroundSyncMode>('every');

        const {$gettext} = useTranslate();

        const syncAutoImportFromIsEnabled = (): void => {
            const f = form.value;
            if (f.source === 'import') {
                f.auto_import_enabled = f.is_enabled;
            }
        };

        const deriveRssBackgroundSyncMode = (): RssBackgroundSyncMode => {
            const f = form.value;
            if (f.source !== 'import') {
                return 'every';
            }
            const h = f.import_sync_before_hours;
            if (h !== null && h !== undefined && h > 0) {
                return 'before_air';
            }
            return 'every';
        };

        const syncRssBackgroundModeFromForm = (): void => {
            syncAutoImportFromIsEnabled();
            rssBackgroundSyncMode.value = deriveRssBackgroundSyncMode();
        };

        const setRssBackgroundSyncMode = (mode: RssBackgroundSyncMode): void => {
            rssBackgroundSyncMode.value = mode;
            const f = form.value;
            if (mode === 'every') {
                f.import_sync_before_hours = null;
            } else {
                if (
                    f.import_sync_before_hours === null
                    || f.import_sync_before_hours === undefined
                    || f.import_sync_before_hours < 1
                ) {
                    f.import_sync_before_hours = 5;
                }
            }
        };

        const importSyncBeforeHoursValid = createRule({
            validator: (value: number | null | undefined) => {
                const f = form.value;
                if (f.source !== 'import' || rssBackgroundSyncMode.value !== 'before_air') {
                    return true;
                }
                if (value === null || value === undefined) {
                    return false;
                }
                const n = Number(value);
                return Number.isInteger(n) && n >= 1 && n <= 168;
            },
            message: () =>
                $gettext('Enter a whole number of hours between 1 and 168.')
        });

        const {r$} = useAppRegle(
            form,
            {
                title: {required},
                description: {required},
                language: {required},
                categories: {
                    required,
                    $each: {}
                },
                source: {required},
                import_sync_before_hours: {
                    importSyncBeforeHoursValid
                },
            },
            {
                validationGroups: (fields) => ({
                    basicInfoTab: [
                        fields.title,
                        fields.link,
                        fields.description,
                        fields.language,
                        fields.author,
                        fields.email,
                        fields.categories,
                        fields.is_enabled
                    ],
                    brandingTab: [
                        fields.branding_config.public_custom_html,
                        fields.branding_config.enable_op3_prefix
                    ],
                    sourceTab: [
                        fields.source,
                        fields.playlist_id,
                        fields.playlist_auto_publish,
                        fields.feed_url,
                        fields.is_enabled,
                        fields.auto_import_enabled,
                        fields.auto_keep_episodes,
                        fields.import_sync_before_hours,
                        fields.episode_storage_type,
                        fields.media_folder_path,
                    ]
                })
            }
        );

        watch(
            [rssBackgroundSyncMode, () => form.value.source],
            () => {
                r$.import_sync_before_hours.$validateSync();
            }
        );

        watch(
            () => form.value.source,
            (s: PodcastRecord['source'], prev: PodcastRecord['source'] | undefined) => {
                if (s === 'import' && prev !== undefined && prev !== 'import') {
                    syncRssBackgroundModeFromForm();
                }
            }
        );

        watch(
            () => [form.value.is_enabled, form.value.source] as const,
            () => {
                syncAutoImportFromIsEnabled();
            }
        );

        const $reset = () => {
            reset();
            resetRecord();
            r$.$reset();
            syncRssBackgroundModeFromForm();
        }

        return {
            record,
            form,
            r$,
            rssBackgroundSyncMode,
            setRssBackgroundSyncMode,
            syncRssBackgroundModeFromForm,
            $reset
        }
    }
);
