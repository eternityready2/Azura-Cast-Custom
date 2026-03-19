import {useAppRegle} from "~/vendor/regle.ts";
import {useResettableRef} from "~/functions/useResettableRef.ts";
import {defineStore} from "pinia";
import {required} from "@regle/rules";
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
            auto_import_enabled: false,
            auto_keep_episodes: 0,
            import_strategy: 'latest_single' as const,
            import_sync_before_hours: null as number | null,
            episode_storage_type: 'podcast',
            media_folder_path: null,
            artwork_file: null,
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
                        fields.auto_import_enabled,
                        fields.auto_keep_episodes,
                        fields.import_strategy,
                        fields.import_sync_before_hours,
                        fields.episode_storage_type,
                        fields.media_folder_path,
                    ]
                })
            }
        );

        const $reset = () => {
            reset();
            resetRecord();
            r$.$reset();
        }

        return {
            record,
            form,
            r$,
            $reset
        }
    }
);
