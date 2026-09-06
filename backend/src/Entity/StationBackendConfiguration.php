<?php

declare(strict_types=1);

namespace App\Entity;

use App\Doctrine\AbstractArrayEntity;
use App\Entity\Enums\ClockWheelDurationEnforcement;
use App\Entity\Enums\StationBackendPerformanceModes;
use App\Radio\Backend\Liquidsoap\EncodingFormat;
use App\Radio\Enums\AudioProcessingMethods;
use App\Radio\Enums\CrossfadeModes;
use App\Radio\Enums\MasterMePresets;
use App\Radio\Enums\StreamFormats;
use App\Utilities\Types;
use LogicException;
use OpenApi\Attributes as OA;

#[OA\Schema(schema: "StationBackendConfiguration", type: "object")]
final class StationBackendConfiguration extends AbstractArrayEntity
{
    /**
     * Migrate legacy Top-of-Hour-named playout controls into their generic homes
     * while loading station JSON. Old keys are intentionally not serialized back.
     *
     * @param array<string, mixed> $data
     */
    public function __construct(array $data = [])
    {
        $legacyPlayoutMap = [
            'top_of_hour_hard_trigger_enabled' => 'playout_hard_clock_enabled',
            'top_of_hour_hard_trigger_seconds' => 'playout_hard_clock_trigger_seconds',
            'top_of_hour_hard_trigger_fade' => 'playout_hard_clock_fade_seconds',
            'top_of_hour_duck_enabled' => 'playout_smart_duck_enabled',
            'top_of_hour_duck_attenuation' => 'playout_smart_duck_attenuation',
            'top_of_hour_duck_delay' => 'playout_smart_duck_delay',
        ];

        foreach ($legacyPlayoutMap as $legacyKey => $newKey) {
            if (!array_key_exists($newKey, $data) && array_key_exists($legacyKey, $data)) {
                $data[$newKey] = $data[$legacyKey];
            }
            unset($data[$legacyKey]);
        }

        // Removed implementation switches from the retired TOH engine.
        unset(
            $data['top_of_hour_id_mode'],
            $data['top_of_hour_finish_buffer_seconds'],
            $data['top_of_hour_pre_id_fade'],
            $data['top_of_hour_pre_id_fade_seconds'],
        );

        parent::__construct($data);
    }

    #[OA\Property]
    public bool $aircheck_enabled = false {
        set (bool|string|null $value) => Types::bool($value);
    }

    #[OA\Property]
    public int $aircheck_interval_minutes = 10 {
        set (int|string|null $value) => $this->aircheck_interval_minutes = max(1, min(60, Types::int($value, 10)));
    }

    #[OA\Property]
    public int $aircheck_last_check = 0 {
        set (int|string|null $value) => Types::int($value, 0);
    }

    /** @var array<int, array<string, mixed>> */
    #[OA\Property(type: 'array')]
    public array $aircheck_interventions = [];

    /** @var array<int, array<string, mixed>> */
    #[OA\Property(type: 'array')]
    public array $feature_shows = [];

    #[OA\Property]
    public string $charset = 'UTF-8' {
        set (?string $value) => Types::string($value, 'UTF-8', true);
    }

    #[OA\Property]
    public ?int $dj_port = null {
        set (int|string|null $value) => Types::intOrNull($value);
    }

    #[OA\Property]
    public ?int $telnet_port = null {
        set (int|string|null $value) => Types::intOrNull($value);
    }

    #[OA\Property]
    public bool $record_streams = false {
        set(string|bool $value) => Types::bool($value, false, true);
    }

    #[OA\Property]
    public string $record_streams_format = '' {
        set (string|StreamFormats|null $value) {
            if ($value instanceof StreamFormats) {
                $value = $value->value;
            } else {
                if ($value !== null) {
                    $value = strtolower($value);
                    if (null === StreamFormats::tryFrom($value)) {
                        $value = null;
                    }
                }
            }

            $this->record_streams_format = $value ?? '';
        }
    }

    public function getRecordStreamsFormatEnum(): StreamFormats
    {
        return StreamFormats::tryFrom($this->record_streams_format) ?? StreamFormats::Mp3;
    }

    #[OA\Property]
    public int $record_streams_bitrate = 128 {
        set (int|string|null $value) => Types::int($value, 128);
    }

    public function getRecordStreamsEncoding(): ?EncodingFormat
    {
        if (!$this->record_streams) {
            return null;
        }

        return new EncodingFormat(
            format: $this->getRecordStreamsFormatEnum(),
            bitrate: $this->record_streams_bitrate,
            subProfile: null
        );
    }

    #[OA\Property]
    public bool $use_manual_autodj = false {
        set (bool|null $value) => Types::bool($value);
    }

    protected const int DEFAULT_QUEUE_LENGTH = 3;

    #[OA\Property]
    public int $autodj_queue_length = self::DEFAULT_QUEUE_LENGTH {
        set(int|string|null $value) => Types::int($value, self::DEFAULT_QUEUE_LENGTH);
    }

    // Optional wall-clock queue lookahead. Zero keeps the normal track-count behavior.
    protected const int DEFAULT_QUEUE_LOOKAHEAD_MINUTES = 0;

    protected const int MAX_QUEUE_LOOKAHEAD_MINUTES = 2880;

    #[OA\Property]
    public int $autodj_queue_lookahead_minutes = self::DEFAULT_QUEUE_LOOKAHEAD_MINUTES {
        set(int|string|null $value) {
            $intVal = Types::int($value, self::DEFAULT_QUEUE_LOOKAHEAD_MINUTES);
            $this->autodj_queue_lookahead_minutes = max(
                0,
                min($intVal, self::MAX_QUEUE_LOOKAHEAD_MINUTES)
            );
        }
    }

    // Keeps the rolling linear log filled by the scheduled background task.
    #[OA\Property]
    public bool $linear_log_enabled = false {
        set(bool|null $value) => Types::bool($value);
    }

    protected const int DEFAULT_LINEAR_LOG_HOURS = 24;

    protected const int MAX_LINEAR_LOG_HOURS = 48;

    #[OA\Property]
    public int $linear_log_hours = self::DEFAULT_LINEAR_LOG_HOURS {
        set(int|string|null $value) {
            $intVal = Types::int($value, self::DEFAULT_LINEAR_LOG_HOURS);
            $this->linear_log_hours = max(1, min($intVal, self::MAX_LINEAR_LOG_HOURS));
        }
    }

    // Generic rigid-clock playout controls. These are deliberately independent
    // from the Top-of-Hour Station ID feature and remain useful when TOH is off.
    #[OA\Property]
    public bool $playout_hard_clock_enabled = false {
        set(bool|string|null $value) => Types::bool($value, false, true);
    }

    protected const float DEFAULT_HARD_CLOCK_TRIGGER_SECONDS = 3.0;

    #[OA\Property]
    public float $playout_hard_clock_trigger_seconds = self::DEFAULT_HARD_CLOCK_TRIGGER_SECONDS {
        set(float|int|string|null $value) {
            $floatVal = Types::float($value, self::DEFAULT_HARD_CLOCK_TRIGGER_SECONDS);
            $this->playout_hard_clock_trigger_seconds = max(1.0, min($floatVal, 30.0));
        }
    }

    protected const float DEFAULT_HARD_CLOCK_FADE_SECONDS = 3.0;

    #[OA\Property]
    public float $playout_hard_clock_fade_seconds = self::DEFAULT_HARD_CLOCK_FADE_SECONDS {
        set(float|int|string|null $value) {
            $floatVal = Types::float($value, self::DEFAULT_HARD_CLOCK_FADE_SECONDS);
            $this->playout_hard_clock_fade_seconds = max(0.0, min($floatVal, 10.0));
        }
    }

    #[OA\Property]
    public bool $playout_stretch_squeeze_enabled = true {
        set(bool|string|null $value) => Types::bool($value, true, true);
    }

    protected const float DEFAULT_STRETCH_SQUEEZE_MAX_PERCENT = 5.0;

    #[OA\Property]
    public float $playout_stretch_squeeze_max_percent = self::DEFAULT_STRETCH_SQUEEZE_MAX_PERCENT {
        set(float|int|string|null $value) {
            $floatVal = Types::float($value, self::DEFAULT_STRETCH_SQUEEZE_MAX_PERCENT);
            $this->playout_stretch_squeeze_max_percent = max(0.5, min($floatVal, 5.0));
        }
    }

    // Generic smart ducking for the interrupting queue.
    #[OA\Property]
    public bool $playout_smart_duck_enabled = false {
        set(bool|string|null $value) => Types::bool($value, false, true);
    }

    protected const float DEFAULT_SMART_DUCK_ATTENUATION = 0.2;

    #[OA\Property]
    public float $playout_smart_duck_attenuation = self::DEFAULT_SMART_DUCK_ATTENUATION {
        set(float|int|string|null $value) {
            $floatVal = Types::float($value, self::DEFAULT_SMART_DUCK_ATTENUATION);
            $this->playout_smart_duck_attenuation = max(0.0, min($floatVal, 1.0));
        }
    }

    protected const float DEFAULT_SMART_DUCK_DELAY = 3.0;

    #[OA\Property]
    public float $playout_smart_duck_delay = self::DEFAULT_SMART_DUCK_DELAY {
        set(float|int|string|null $value) {
            $floatVal = Types::float($value, self::DEFAULT_SMART_DUCK_DELAY);
            $this->playout_smart_duck_delay = max(0.5, min($floatVal, 15.0));
        }
    }

    #[OA\Property]
    public string $dj_mount_point = '/' {
        set (string|null $value) => Types::string($value, '/', true);
    }

    protected const int DEFAULT_DJ_BUFFER = 5;

    #[OA\Property]
    public int $dj_buffer = self::DEFAULT_DJ_BUFFER {
        set (int|string|null $value) => Types::int($value, self::DEFAULT_DJ_BUFFER);
    }

    #[OA\Property]
    public string $audio_processing_method = '' {
        set(string|AudioProcessingMethods|null $value) {
            if ($value instanceof AudioProcessingMethods) {
                $value = $value->value;
            } else {
                if ($value !== null) {
                    $value = strtolower($value);
                    if (null === AudioProcessingMethods::tryFrom($value)) {
                        $value = null;
                    }
                }
            }

            $this->audio_processing_method = $value ?? '';
        }
    }

    public function getAudioProcessingMethodEnum(): AudioProcessingMethods
    {
        return AudioProcessingMethods::tryFrom($this->audio_processing_method)
            ?? AudioProcessingMethods::default();
    }

    public function isAudioProcessingEnabled(): bool
    {
        return AudioProcessingMethods::None !== $this->getAudioProcessingMethodEnum();
    }

    #[OA\Property]
    public bool $post_processing_include_live = false {
        set (bool|string|null $value) => Types::bool($value);
    }

    #[OA\Property]
    public ?string $stereo_tool_license_key = null {
        set => Types::stringOrNull($value, true);
    }

    #[OA\Property]
    public ?string $stereo_tool_configuration_path = null {
        set => Types::stringOrNull($value, true);
    }

    #[OA\Property]
    public ?string $master_me_preset = null {
        set (string|MasterMePresets|null $value) {
            if ($value instanceof MasterMePresets) {
                $value = $value->value;
            } elseif ($value !== null) {
                $value = strtolower($value);

                if (null === MasterMePresets::tryFrom($value)) {
                    $value = null;
                }
            }

            $this->master_me_preset = $value;
        }
    }

    public function getMasterMePresetEnum(): MasterMePresets
    {
        return MasterMePresets::tryFrom($this->master_me_preset ?? '')
            ?? MasterMePresets::default();
    }

    protected const int MASTER_ME_DEFAULT_LOUDNESS_TARGET = -16;

    #[OA\Property]
    public int $master_me_loudness_target = self::MASTER_ME_DEFAULT_LOUDNESS_TARGET {
        set (int|string|null $value) => Types::int($value, self::MASTER_ME_DEFAULT_LOUDNESS_TARGET);
    }

    #[OA\Property]
    public bool $enable_replaygain_metadata = false {
        get => ($this->enable_auto_cue) ? false : $this->enable_replaygain_metadata;
        set (bool|string|null $value) => Types::bool($value, false, true);
    }

    #[OA\Property]
    public string $crossfade_type = '';

    public function getCrossfadeTypeEnum(): CrossfadeModes
    {
        // AutoCue overrides this functionality.
        if ($this->enable_auto_cue) {
            return CrossfadeModes::Disabled;
        }

        return CrossfadeModes::tryFrom($this->crossfade_type) ?? CrossfadeModes::default();
    }

    protected const float DEFAULT_CROSSFADE_DURATION = 2.0;

    #[OA\Property]
    public float $crossfade = self::DEFAULT_CROSSFADE_DURATION {
        set (float|string|null $value) => round(
            Types::float($value, self::DEFAULT_CROSSFADE_DURATION),
            1
        );
    }

    public function getCrossfadeDuration(): float
    {
        $crossfade = $this->crossfade;
        $crossfadeType = $this->getCrossfadeTypeEnum();

        if (CrossfadeModes::Disabled !== $crossfadeType && $crossfade > 0) {
            return round($crossfade * 1.5, 2);
        }

        return 0;
    }

    public function isCrossfadeEnabled(): bool
    {
        return $this->getCrossfadeDuration() > 0;
    }

    protected const float DEFAULT_CROSSFADE_SMART_HIGH = -15.0;

    #[OA\Property(
        description: "The dB level above which a track is considered 'loud' ('high' parameter in 'cross.smart')."
    )]
    public float $crossfade_smart_high = self::DEFAULT_CROSSFADE_SMART_HIGH {
        set (float|string|null $value) => round(
            Types::float($value, self::DEFAULT_CROSSFADE_SMART_HIGH),
            1
        );
    }

    protected const float DEFAULT_CROSSFADE_SMART_MEDIUM = -32.0;

    #[OA\Property(
        description: "The dB level below which a track is considered 'quiet' ('medium' parameter in 'cross.smart')."
    )]
    public float $crossfade_smart_medium = self::DEFAULT_CROSSFADE_SMART_MEDIUM {
        set (float|string|null $value) => round(
            Types::float($value, self::DEFAULT_CROSSFADE_SMART_MEDIUM),
            1
        );
    }

    protected const float DEFAULT_CROSSFADE_SMART_MARGIN = 8.0;

    #[OA\Property(
        description: "The dB difference above which two tracks are considered too different to overlap ('margin' parameter in 'cross.smart')."
    )]
    public float $crossfade_smart_margin = self::DEFAULT_CROSSFADE_SMART_MARGIN {
        set (float|string|null $value) => round(
            Types::float($value, self::DEFAULT_CROSSFADE_SMART_MARGIN),
            1
        );
    }

    protected const int DEFAULT_DUPLICATE_PREVENTION_TIME_RANGE = 120;

    #[OA\Property]
    public int $duplicate_prevention_time_range = self::DEFAULT_DUPLICATE_PREVENTION_TIME_RANGE {
        set (int|string|null $value) => Types::int($value, self::DEFAULT_DUPLICATE_PREVENTION_TIME_RANGE);
    }

    #[OA\Property(example: 'php')]
    public string $clock_wheel_duration_enforcement = '' {
        set (string|ClockWheelDurationEnforcement|null $value) {
            if ($value instanceof ClockWheelDurationEnforcement) {
                $value = $value->value;
            } elseif ($value !== null) {
                $value = strtolower($value);
                if (null === ClockWheelDurationEnforcement::tryFrom($value)) {
                    $value = null;
                }
            }

            $this->clock_wheel_duration_enforcement = $value ?? '';
        }
    }

    public function getClockWheelDurationEnforcementEnum(): ClockWheelDurationEnforcement
    {
        return ClockWheelDurationEnforcement::tryFrom($this->clock_wheel_duration_enforcement)
            ?? ClockWheelDurationEnforcement::Php;
    }

    #[OA\Property]
    public string $performance_mode = '' {
        set(string|StationBackendPerformanceModes|null $value) {
            if ($value instanceof StationBackendPerformanceModes) {
                $value = $value->value;
            } else {
                if ($value !== null) {
                    if (null === StationBackendPerformanceModes::tryFrom($value)) {
                        $value = null;
                    }
                }
            }

            $this->performance_mode = $value ?? '';
        }
    }

    public function getPerformanceModeEnum(): StationBackendPerformanceModes
    {
        return StationBackendPerformanceModes::tryFrom($this->performance_mode)
            ?? StationBackendPerformanceModes::default();
    }

    #[OA\Property]
    public int $hls_segment_length = 4 {
        set (int|string|null $value) => Types::int($value, 4);
    }

    #[OA\Property]
    public int $hls_segments_in_playlist = 5 {
        set (int|string|null $value) => Types::int($value, 5);
    }

    #[OA\Property]
    public int $hls_segments_overhead = 2 {
        set (int|string|null $value) => Types::int($value, 2);
    }

    #[OA\Property]
    public bool $hls_enable_on_public_player = false {
        set (bool|string|null $value) => Types::bool($value, false, true);
    }

    #[OA\Property]
    public bool $hls_is_default = false {
        set (bool|string|null $value) => Types::bool($value, false, true);
    }

    #[OA\Property]
    public string $live_broadcast_text = 'Live Broadcast' {
        set (string|null $value) => Types::string($value, 'Live Broadcast');
    }

    #[OA\Property]
    public bool $enable_auto_cue = false;

    #[OA\Property]
    public bool $write_playlists_to_liquidsoap = false;

    #[OA\Property]
    public bool $share_encoders = false;

    /** AI News Bulletin Settings */

    #[OA\Property]
    public bool $ai_news_enabled = false {
        set (bool|string|null $value) => Types::bool($value, false, true);
    }

    #[OA\Property]
    public ?string $ai_news_intro = null {
        set => Types::stringOrNull($value, true);
    }

    #[OA\Property]
    public ?string $ai_news_reporter_name = null {
        set => Types::stringOrNull($value, true);
    }

    #[OA\Property]
    public string $ai_news_source_urls = '' {
        set (?string $value) => Types::string($value, '', true);
    }

    #[OA\Property]
    public int $ai_news_story_count = 10 {
        set (int|string|null $value) => max(1, min(25, Types::int($value, 10)));
    }

    #[OA\Property]
    public ?string $ai_news_active_hours = null {
        set => Types::stringOrNull($value, true);
    }

    /** @var int[] */
    #[OA\Property(
        description: 'Array of ISO-8601 days (1 for Monday, 7 for Sunday)'
    )]
    public array $ai_news_active_days = [] {
        set (mixed $value) {
            $days = array_map(
                static fn(mixed $day): int => (int)$day,
                Types::array($value)
            );

            $days = array_values(array_unique(array_filter(
                $days,
                static fn(int $day): bool => $day >= 1 && $day <= 7
            )));
            sort($days);

            $this->ai_news_active_days = $days;
        }
    }

    #[OA\Property]
    public bool $ai_news_top_of_hour = true {
        set (bool|string|null $value) => Types::bool($value, true, true);
    }

    #[OA\Property]
    public bool $ai_news_bottom_of_hour = false {
        set (bool|string|null $value) => Types::bool($value, false, true);
    }

    // Rebuilt automatic station-wide Top-of-Hour Station ID controls.
    #[OA\Property]
    public bool $top_of_hour_id_enabled = false {
        set (bool|string|null $value) => Types::bool($value, false, true);
    }

    #[OA\Property]
    public int $top_of_hour_lookahead_minutes = 10 {
        set (int|string|null $value) => Types::int($value, 10);
    }

    #[OA\Property]
    public int $top_of_hour_compliance_tolerance_seconds = 10 {
        set (int|string|null $value) => Types::int($value, 10);
    }

    #[OA\Property]
    public int $top_of_hour_id_max_seconds = 60 {
        set (int|string|null $value) => Types::int($value, 60);
    }

    // DMCA compliance settings.

    #[OA\Property(description: 'Enable DMCA compliance enforcement at the queue level.')]
    public bool $dmca_compliance_enabled = false {
        set (bool|string|int|null $value) => Types::bool($value, false);
    }

    #[OA\Property(description: 'Rolling window in minutes for DMCA play-count checks. Default: 180 (3 hours).')]
    public int $dmca_window_minutes = 180 {
        set (int|string|null $value) => Types::int($value, 180);
    }

    #[OA\Property(description: 'Max plays of the same song in the rolling window. DMCA default: 3.')]
    public int $dmca_max_song_plays = 3 {
        set (int|string|null $value) => Types::int($value, 3);
    }

    #[OA\Property(description: 'Max consecutive plays of the same song. DMCA default: 2.')]
    public int $dmca_max_consecutive_song = 2 {
        set (int|string|null $value) => Types::int($value, 2);
    }

    #[OA\Property(description: 'Max plays from the same album in the rolling window. DMCA default: 3.')]
    public int $dmca_max_album_plays = 3 {
        set (int|string|null $value) => Types::int($value, 3);
    }

    #[OA\Property(description: 'Max plays by the same artist in the rolling window. DMCA default: 4.')]
    public int $dmca_max_artist_plays = 4 {
        set (int|string|null $value) => Types::int($value, 4);
    }

    #[OA\Property(description: 'Max consecutive plays by the same artist. DMCA default: 3.')]
    public int $dmca_max_consecutive_artist = 3 {
        set (int|string|null $value) => Types::int($value, 3);
    }

    #[OA\Property]
    public bool $analytics_exclude_bots = true {
        set (bool|string|null $value) => Types::bool($value, true, true);
    }

    #[OA\Property]
    public bool $content_type_crossfade_enabled = true {
        set (bool|string|null $value) => Types::bool($value, true, true);
    }

    /**
     * Transition keys: "from_type:to_type" map to {fade_in, fade_out}, or null for the station default.
     *
     * @var array<string, array{fade_in: float, fade_out: float}|null>
     */
    #[OA\Property(type: 'object')]
    public array $content_type_crossfade_matrix = [] {
        set (array|string|null $value) {
            $this->content_type_crossfade_matrix = is_array($value) ? $value : [];
        }
    }

    /**
     * Named profile overrides merged on top of the station matrix.
     *
     * @var array<string, array<string, array{fade_in: float, fade_out: float}|null>>
     */
    #[OA\Property(type: 'object')]
    public array $crossfade_profiles = [] {
        set (array|string|null $value) {
            $this->crossfade_profiles = is_array($value) ? $value : [];
        }
    }

    /**
     * Optional AI DJ content category slugs the station has removed as tabs.
     * Required categories (song intros / post-song) are never stored here.
     *
     * @var list<string>
     */
    #[OA\Property(
        description: 'AI DJ content type slugs hidden from the Content Library tab list',
        type: 'array',
        items: new OA\Items(type: 'string')
    )]
    public array $ai_dj_removed_content_types = [] {
        set (mixed $value) {
            $types = array_values(array_unique(array_filter(
                array_map(
                    static fn(mixed $type): string => strtolower(trim((string)$type)),
                    Types::array($value)
                ),
                static fn(string $type): bool => $type !== ''
            )));

            $this->ai_dj_removed_content_types = $types;
        }
    }

    #[OA\Property]
    public ?string $ai_news_voice_model_path = null {
        set => Types::stringOrNull($value, true);
    }

    #[OA\Property]
    public ?string $ai_news_outro = null {
        set => Types::stringOrNull($value, true);
    }

    /*
     * Liquidsoap Custom Configuration Sections
     */

    public const string CUSTOM_TOP = 'custom_config_top';

    #[OA\Property(
        description: 'Custom Liquidsoap Configuration: Top Section'
    )]
    public ?string $custom_config_top = null;

    public const string CUSTOM_PRE_PLAYLISTS = 'custom_config_pre_playlists';

    #[OA\Property(
        description: 'Custom Liquidsoap Configuration: Pre-Playlists Section'
    )]
    public ?string $custom_config_pre_playlists = null;

    public const string CUSTOM_PRE_LIVE = 'custom_config_pre_live';

    #[OA\Property(
        description: 'Custom Liquidsoap Configuration: Pre-Live Section'
    )]
    public ?string $custom_config_pre_live = null;

    public const string CUSTOM_PRE_FADE = 'custom_config_pre_fade';

    #[OA\Property(
        description: 'Custom Liquidsoap Configuration: Pre-Fade Section'
    )]
    public ?string $custom_config_pre_fade = null;

    public const string CUSTOM_PRE_BROADCAST = 'custom_config';

    #[OA\Property(
        description: 'Custom Liquidsoap Configuration: Pre-Broadcast Section'
    )]
    public ?string $custom_config = null;

    public const string CUSTOM_BOTTOM = 'custom_config_bottom';

    #[OA\Property(
        description: 'Custom Liquidsoap Configuration: Post-Broadcast Section'
    )]
    public ?string $custom_config_bottom = null;

    /** @return array<int, string> */
    public static function getCustomConfigurationSections(): array
    {
        return [
            self::CUSTOM_TOP,
            self::CUSTOM_PRE_PLAYLISTS,
            self::CUSTOM_PRE_FADE,
            self::CUSTOM_PRE_LIVE,
            self::CUSTOM_PRE_BROADCAST,
            self::CUSTOM_BOTTOM,
        ];
    }

    public function getCustomConfigurationSection(string $section): ?string
    {
        $allSections = self::getCustomConfigurationSections();
        if (!in_array($section, $allSections, true)) {
            throw new LogicException('Invalid custom configuration section.');
        }

        return $this->$section;
    }

    public function setCustomConfigurationSection(string $section, ?string $value = null): void
    {
        $allSections = self::getCustomConfigurationSections();
        if (!in_array($section, $allSections, true)) {
            throw new LogicException('Invalid custom configuration section.');
        }

        $this->$section = $value;
    }
}
