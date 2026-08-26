from pathlib import Path

planner = Path('backend/src/Radio/AutoDJ/HourBoundaryPlanner.php')
text = planner.read_text()
old = '''    /**
     * Maximum music duration before the late-hour protection reserve begins.
     * Returns null outside the lookahead window or once the reserve is open.
     *
     * The full :58/:59 ID planning window is intentionally not used here: doing
     * so caused ordinary music to be shortened at :58 even when the next hour
     * had no scheduled content. Runtime TOH ownership still uses the wider window.
     */
    public function secondsAvailableForMusicBeforeTopOfHour(
'''
new = '''    /**
     * Real music time remaining before the late-hour TOH handoff begins.
     * Returns null outside the configured lookahead and zero once the reserve
     * is open. Queue selection uses this full value for precision backtiming;
     * the legacy cap helper below still suppresses unusably short cue-out caps.
     *
     * The full :58/:59 ID planning window is intentionally not used here: doing
     * so caused ordinary music to be shortened at :58 even when the next hour
     * had no scheduled content. Runtime TOH ownership still uses the wider window.
     */
    public function secondsAvailableForMusicBeforeTopOfHour(
'''
if old in text:
    planner.write_text(text.replace(old, new, 1))
