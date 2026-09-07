# Top-of-Hour live regression checklist

This checklist exists because syntax-only validation cannot prove an on-air transition.

After deploying a Top-of-Hour runtime change, verify one open-hour boundary and one rigid `:00` boundary on a real station:

1. Ordinary AutoDJ music is playing before the configured `:59:ss` ID target.
2. The current song fades smoothly into the station ID.
3. The interrupted song never resumes after the ID.
4. On an open hour, the next music item is a fresh AutoDJ request. AI News may play first only when its own configured active schedule permits it.
5. On a rigid hour, the scheduled programme owns `:00` and no AutoDJ song competes with it.
6. After the rigid programme ends, ordinary AutoDJ resumes with a fresh item.

A green CI run confirms syntax, static analysis, and generated configuration. It does not replace this on-air regression test.
