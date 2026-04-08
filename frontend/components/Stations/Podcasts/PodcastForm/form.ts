import {useAppRegle} from "~/vendor/regle.ts";
import {useResettableRef} from "~/functions/useResettableRef.ts";
import {defineStore} from "pinia";
import {required} from "@regle/rules";
import {watch} from "vue";
import {PodcastExtraData, PodcastRecord} from "~/entities/Podcasts.ts";

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
            episode_storage_type: 'media',
            media_folder_path: '',
            artwork_file: null,
        });

        const syncAutoImportFromIsEnabled = (): void => {
            const f = form.value;
            if (f.source === 'import') {
                f.auto_import_enabled = f.is_enabled;
            }
        };


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
                        fields.episode_storage_type,
                        fields.media_folder_path,
                    ]
                })
            }
        );

        watch(
            () => form.value.source,
            (s: PodcastRecord['source'], prev: PodcastRecord['source'] | undefined) => {
                if (s === 'import' && prev !== undefined && prev !== 'import') {
                    syncAutoImportFromIsEnabled();
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
            syncAutoImportFromIsEnabled();
        }

        return {
            record,
            form,
            r$,
            $reset
        }
    }
);
