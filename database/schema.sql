-- ============================================================
-- Lennarts Diktat-Trainer — Datenbankschema
-- ============================================================

-- Block 1 — Nutzer & Einstellungen

CREATE TABLE IF NOT EXISTS users (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  username     VARCHAR(50)  UNIQUE NOT NULL,
  display_name VARCHAR(100) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role         TEXT CHECK(role IN ('superadmin','admin','child')) NOT NULL,
  grade_level  INTEGER NULL,
  school_type  VARCHAR(50)  NULL,
  theme        VARCHAR(50)  DEFAULT 'minecraft',
  created_at   DATETIME     DEFAULT CURRENT_TIMESTAMP,
  last_login   DATETIME     NULL,
  active       INTEGER      DEFAULT 1
);

CREATE TABLE IF NOT EXISTS child_admins (
  child_id   INTEGER NOT NULL REFERENCES users(id),
  admin_id   INTEGER NOT NULL REFERENCES users(id),
  role       TEXT CHECK(role IN ('primary','secondary')) DEFAULT 'primary',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (child_id, admin_id)
);

CREATE TABLE IF NOT EXISTS settings (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id       INTEGER NOT NULL REFERENCES users(id),
  key           VARCHAR(100) NOT NULL,
  value_encrypted TEXT NOT NULL,
  updated_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE(user_id, key)
);

-- Block 2 — Wortmaterial

CREATE TABLE IF NOT EXISTS words (
  id               INTEGER PRIMARY KEY AUTOINCREMENT,
  word             VARCHAR(100) NOT NULL,
  language         VARCHAR(10)  DEFAULT 'de',
  primary_category VARCHAR(10)  NOT NULL,
  grade_level      INTEGER      NOT NULL,
  difficulty       INTEGER      DEFAULT 1,
  source           TEXT CHECK(source IN ('kmk','ai_generated','manual')) DEFAULT 'kmk',
  federal_state    VARCHAR(50)  NULL,
  curriculum_ref   VARCHAR(100) NULL,
  active           INTEGER      DEFAULT 1,
  created_at       DATETIME     DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS word_categories (
  word_id  INTEGER NOT NULL REFERENCES words(id),
  category VARCHAR(10) NOT NULL,
  PRIMARY KEY (word_id, category)
);

CREATE TABLE IF NOT EXISTS sentences (
  id               INTEGER PRIMARY KEY AUTOINCREMENT,
  sentence         TEXT    NOT NULL,
  primary_category VARCHAR(10) NOT NULL,
  grade_level      INTEGER NOT NULL,
  theme            VARCHAR(50) NULL,
  format           TEXT CHECK(format IN ('gap','sentence','mini_diktat')) NOT NULL,
  difficulty       INTEGER DEFAULT 1,
  source           TEXT CHECK(source IN ('ai','manual')) DEFAULT 'ai',
  times_used       INTEGER DEFAULT 0,
  quality_score    INTEGER NULL,
  active           INTEGER DEFAULT 1,
  created_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
  created_by       INTEGER NULL REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS sentence_words (
  sentence_id INTEGER NOT NULL REFERENCES sentences(id),
  word_id     INTEGER NOT NULL REFERENCES words(id),
  position    INTEGER NOT NULL,
  is_test_word INTEGER DEFAULT 0,
  PRIMARY KEY (sentence_id, word_id)
);

CREATE TABLE IF NOT EXISTS child_sentence_history (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id     INTEGER NOT NULL REFERENCES users(id),
  sentence_id INTEGER NOT NULL REFERENCES sentences(id),
  used_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
  was_correct INTEGER NULL
);

-- Block 3 — Test

CREATE TABLE IF NOT EXISTS tests (
  id               INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id          INTEGER NOT NULL REFERENCES users(id),
  type             TEXT CHECK(type IN ('initial','progress')) NOT NULL,
  status           TEXT CHECK(status IN ('pending','in_progress','completed','aborted')) DEFAULT 'pending',
  started_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
  completed_at     DATETIME NULL,
  session_count    INTEGER DEFAULT 1,
  ai_fatigue_score INTEGER NULL,
  ai_notes         TEXT NULL,
  compared_to_test INTEGER NULL REFERENCES tests(id)
);

CREATE TABLE IF NOT EXISTS test_sections (
  id              INTEGER PRIMARY KEY AUTOINCREMENT,
  test_id         INTEGER NOT NULL REFERENCES tests(id),
  block           TEXT CHECK(block IN ('A','B','C','D')) NOT NULL,
  status          TEXT CHECK(status IN ('pending','in_progress','completed','skipped')) DEFAULT 'pending',
  order_index     INTEGER NOT NULL,
  started_at      DATETIME NULL,
  completed_at    DATETIME NULL,
  ai_recommendation TEXT NULL
);

CREATE TABLE IF NOT EXISTS test_items (
  id              INTEGER PRIMARY KEY AUTOINCREMENT,
  section_id      INTEGER NOT NULL REFERENCES test_sections(id),
  word_id         INTEGER NULL REFERENCES words(id),
  sentence_id     INTEGER NULL REFERENCES sentences(id),
  format          TEXT CHECK(format IN ('word','gap','sentence')) NOT NULL,
  order_index     INTEGER NOT NULL,
  played_at       DATETIME NULL,
  replay_count    INTEGER DEFAULT 0,
  answered_at     DATETIME NULL,
  user_input      VARCHAR(255) NULL,
  is_correct      INTEGER NULL,
  error_categories TEXT NULL,
  response_time_ms INTEGER NULL,
  ai_feedback     TEXT NULL
);

CREATE TABLE IF NOT EXISTS test_results (
  id             INTEGER PRIMARY KEY AUTOINCREMENT,
  test_id        INTEGER NOT NULL REFERENCES tests(id),
  block          TEXT CHECK(block IN ('A','B','C','D')) NOT NULL,
  category       VARCHAR(10) NOT NULL,
  total_items    INTEGER NOT NULL,
  correct_items  INTEGER NOT NULL,
  error_rate     REAL NOT NULL,
  severity       TEXT CHECK(severity IN ('none','mild','moderate','severe')) NOT NULL,
  strategy_level INTEGER NOT NULL,
  compared_delta REAL NULL
);

-- Block 4 — Lernplan / Questlog

CREATE TABLE IF NOT EXISTS learning_plans (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id      INTEGER NOT NULL REFERENCES users(id),
  test_id      INTEGER NOT NULL REFERENCES tests(id),
  status       TEXT CHECK(status IN ('draft','active','completed','superseded')) DEFAULT 'draft',
  created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
  activated_at DATETIME NULL,
  admin_notes  TEXT NULL,
  ai_notes     TEXT NULL
);

CREATE TABLE IF NOT EXISTS plan_biomes (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  plan_id      INTEGER NOT NULL REFERENCES learning_plans(id),
  block        TEXT CHECK(block IN ('A','B','C','D')) NOT NULL,
  name         VARCHAR(100) NOT NULL,
  theme_biome  VARCHAR(50)  NOT NULL,
  order_index  INTEGER NOT NULL,
  status       TEXT CHECK(status IN ('locked','active','completed')) DEFAULT 'locked',
  unlocked_at  DATETIME NULL,
  completed_at DATETIME NULL
);

CREATE TABLE IF NOT EXISTS quests (
  id             INTEGER PRIMARY KEY AUTOINCREMENT,
  biome_id       INTEGER NOT NULL REFERENCES plan_biomes(id),
  category       VARCHAR(10) NOT NULL,
  title          VARCHAR(100) NOT NULL,
  description    TEXT NULL,
  order_index    INTEGER NOT NULL,
  status         TEXT CHECK(status IN ('locked','active','completed','skipped')) DEFAULT 'locked',
  difficulty     INTEGER DEFAULT 1,
  required_score INTEGER DEFAULT 80,
  unlocked_at    DATETIME NULL,
  completed_at   DATETIME NULL,
  ai_notes       TEXT NULL
);

CREATE TABLE IF NOT EXISTS plan_units (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  quest_id      INTEGER NOT NULL REFERENCES quests(id),
  order_index   INTEGER NOT NULL,
  format        TEXT CHECK(format IN ('word','gap','sentence','mini_diktat')) NOT NULL,
  word_count    INTEGER DEFAULT 20,
  difficulty    INTEGER DEFAULT 1,
  status        TEXT CHECK(status IN ('pending','active','completed','skipped')) DEFAULT 'pending',
  scheduled_for DATE NULL,
  completed_at  DATETIME NULL,
  ai_notes      TEXT NULL
);

CREATE TABLE IF NOT EXISTS plan_amendments (
  id             INTEGER PRIMARY KEY AUTOINCREMENT,
  plan_id        INTEGER NOT NULL REFERENCES learning_plans(id),
  created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
  trigger_type   TEXT CHECK(trigger_type IN ('session_result','admin_manual','progress_test','stagnation','mastery')) NOT NULL,
  trigger_ref_id INTEGER NULL,
  changes_json   TEXT NOT NULL,
  ai_reasoning   TEXT NOT NULL,
  admin_approved INTEGER DEFAULT 1
);

-- Block 5 — Übungseinheit / Sessions

CREATE TABLE IF NOT EXISTS generated_content (
  id             INTEGER PRIMARY KEY AUTOINCREMENT,
  type           TEXT CHECK(type IN ('diktat','sentence','word_list','feedback','exercise_plan','test_analysis')) NOT NULL,
  ai_provider    TEXT CHECK(ai_provider IN ('claude','openai','gemini')) NOT NULL,
  model_version  VARCHAR(50) NOT NULL,
  prompt_used    TEXT NOT NULL,
  content_json   TEXT NOT NULL,
  grade_level    INTEGER NULL,
  category       VARCHAR(10) NULL,
  theme          VARCHAR(50) NULL,
  format         TEXT CHECK(format IN ('word','gap','sentence','mini_diktat')) NULL,
  difficulty     INTEGER NULL,
  quality_score  INTEGER NULL,
  times_used     INTEGER DEFAULT 0,
  active         INTEGER DEFAULT 1,
  created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
  created_for    INTEGER NULL REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS sessions (
  id                   INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id              INTEGER NOT NULL REFERENCES users(id),
  plan_unit_id         INTEGER NULL REFERENCES plan_units(id),
  custom_adventure_id  INTEGER NULL REFERENCES custom_adventures(id),
  status               TEXT CHECK(status IN ('active','completed','aborted')) DEFAULT 'active',
  started_at           DATETIME DEFAULT CURRENT_TIMESTAMP,
  completed_at         DATETIME NULL,
  duration_seconds     INTEGER NULL,
  total_items          INTEGER DEFAULT 0,
  correct_first_try    INTEGER DEFAULT 0,
  correct_second_try   INTEGER DEFAULT 0,
  wrong_total          INTEGER DEFAULT 0,
  fatigue_score        INTEGER NULL,
  motivation_score     INTEGER NULL,
  ai_summary           TEXT NULL,
  ai_next_action       TEXT NULL
);

CREATE TABLE IF NOT EXISTS session_items (
  id                   INTEGER PRIMARY KEY AUTOINCREMENT,
  session_id           INTEGER NOT NULL REFERENCES sessions(id),
  word_id              INTEGER NULL REFERENCES words(id),
  sentence_id          INTEGER NULL REFERENCES sentences(id),
  generated_content_id INTEGER NULL REFERENCES generated_content(id),
  custom_text          VARCHAR(500) NULL,
  format               TEXT CHECK(format IN ('word','gap','sentence','mini_diktat')) NOT NULL,
  order_index          INTEGER NOT NULL,
  tts_replays          INTEGER DEFAULT 0,
  tts_slow_replays     INTEGER DEFAULT 0,
  second_try_allowed   INTEGER DEFAULT 0,
  final_correct        INTEGER NULL,
  response_time_ms     INTEGER NULL,
  ai_feedback          TEXT NULL,
  error_categories     TEXT NULL
);

CREATE TABLE IF NOT EXISTS session_attempts (
  id             INTEGER PRIMARY KEY AUTOINCREMENT,
  item_id        INTEGER NOT NULL REFERENCES session_items(id),
  attempt_number INTEGER NOT NULL,
  user_input     VARCHAR(255) NOT NULL,
  is_correct     INTEGER NOT NULL,
  answered_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
  error_type     VARCHAR(50) NULL
);

-- Block 6 — Fortschritt / Achievements

CREATE TABLE IF NOT EXISTS progress_snapshots (
  id              INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id         INTEGER NOT NULL REFERENCES users(id),
  created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
  trigger_type    TEXT CHECK(trigger_type IN ('session','test','quest_completed','biome_completed')) NOT NULL,
  trigger_ref_id  INTEGER NULL,
  category_scores TEXT NOT NULL,
  overall_score   REAL NOT NULL,
  active_biome    VARCHAR(10) NULL,
  active_quest    VARCHAR(10) NULL,
  streak_days     INTEGER DEFAULT 0,
  total_sessions  INTEGER DEFAULT 0,
  total_words     INTEGER DEFAULT 0
);

CREATE TABLE IF NOT EXISTS achievement_definitions (
  id              INTEGER PRIMARY KEY AUTOINCREMENT,
  code            VARCHAR(50) UNIQUE NOT NULL,
  title           VARCHAR(100) NOT NULL,
  description     TEXT NOT NULL,
  icon            VARCHAR(50) NOT NULL,
  category        TEXT CHECK(category IN ('learning','streak','collection','special')) NOT NULL,
  trigger_type    VARCHAR(50) NOT NULL,
  trigger_value   INTEGER NULL,
  unlocks_theme   VARCHAR(50) NULL,
  unlocks_feature VARCHAR(50) NULL,
  is_secret       INTEGER DEFAULT 0,
  active          INTEGER DEFAULT 1
);

CREATE TABLE IF NOT EXISTS user_achievements (
  id             INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id        INTEGER NOT NULL REFERENCES users(id),
  achievement_id INTEGER NOT NULL REFERENCES achievement_definitions(id),
  unlocked_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
  seen_by_user   INTEGER DEFAULT 0,
  UNIQUE(user_id, achievement_id)
);

CREATE TABLE IF NOT EXISTS unlocked_content (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id      INTEGER NOT NULL REFERENCES users(id),
  content_type TEXT CHECK(content_type IN ('theme','feature')) NOT NULL,
  content_key  VARCHAR(50) NOT NULL,
  unlocked_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
  unlocked_by  INTEGER NOT NULL REFERENCES achievement_definitions(id)
);

-- Block 7 — Generierte Inhalte (Ergänzungen)

CREATE TABLE IF NOT EXISTS generated_content_words (
  content_id   INTEGER NOT NULL REFERENCES generated_content(id),
  word_id      INTEGER NOT NULL REFERENCES words(id),
  is_test_word INTEGER DEFAULT 0,
  position     INTEGER NOT NULL,
  PRIMARY KEY (content_id, word_id)
);

CREATE TABLE IF NOT EXISTS ai_interactions (
  id               INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id          INTEGER NULL REFERENCES users(id),
  session_id       INTEGER NULL REFERENCES sessions(id),
  test_id          INTEGER NULL REFERENCES tests(id),
  type             TEXT CHECK(type IN ('feedback','plan_generation','plan_amendment','test_analysis','content_generation','fatigue_check')) NOT NULL,
  ai_provider      TEXT CHECK(ai_provider IN ('claude','openai','gemini')) NOT NULL,
  model_version    VARCHAR(50) NOT NULL,
  prompt_tokens    INTEGER NULL,
  completion_tokens INTEGER NULL,
  cost_estimate    REAL NULL,
  prompt_used      TEXT NOT NULL,
  response_json    TEXT NOT NULL,
  duration_ms      INTEGER NULL,
  created_at       DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Language-aware Architektur (vorbereitet)

CREATE TABLE IF NOT EXISTS categories (
  code        VARCHAR(10) PRIMARY KEY,
  language    VARCHAR(10) DEFAULT 'de',
  block       VARCHAR(5),
  label       VARCHAR(100),
  description TEXT,
  sort_order  INTEGER
);

-- Seed: Fehlerkategorien Deutsch

INSERT OR IGNORE INTO categories (code, language, block, label, description, sort_order) VALUES
  ('A1', 'de', 'A', 'Auslautverhärtung',      'z.B. "Hund→Hunt", "Weg→Wek"',                  1),
  ('A2', 'de', 'A', 'Vokallänge (kurz/lang)',  'z.B. "Miete→Mite", "Mitte→Mite"',              2),
  ('A3', 'de', 'A', 'Konsonantenhäufungen',    'z.B. "Strumpf→Sturmpf"',                        3),
  ('B1', 'de', 'B', 'Doppelkonsonanten',       'z.B. "Mutter→Muter"',                           4),
  ('B2', 'de', 'B', 'ck / tz',                 'z.B. "Brücke→Brüke", "Katze→Katse"',           5),
  ('B3', 'de', 'B', 'ie / ih / i',             'z.B. "Tier→Tir", "ihm→im"',                    6),
  ('B4', 'de', 'B', 'Dehnungs-h',              'z.B. "fahren→faren"',                           7),
  ('B5', 'de', 'B', 'sp / st',                 'z.B. "Straße→Sdrasse"',                         8),
  ('C1', 'de', 'C', 'ä vs. e',                 'z.B. "Hände→Hende"',                            9),
  ('C2', 'de', 'C', 'äu vs. eu',               'z.B. "Häuser→Heuser"',                         10),
  ('C3', 'de', 'C', 'dass / das',              'grammatische Unterscheidung',                   11),
  ('D1', 'de', 'D', 'Konkrete Nomen',          'z.B. "Fahrrad", "Hund"',                       12),
  ('D2', 'de', 'D', 'Abstrakte Nomen',         'z.B. "Freundschaft", "Angst"',                 13),
  ('D3', 'de', 'D', 'Nominalisierungen',       'z.B. "das Laufen", "beim Essen"',              14),
  ('D4', 'de', 'D', 'Satzanfang',              'unter Schreibdruck vergessen',                  15);

-- Seed: Achievement-Definitionen

INSERT OR IGNORE INTO achievement_definitions
  (code, title, description, icon, category, trigger_type, trigger_value, unlocks_theme, unlocks_feature, is_secret)
VALUES
  -- Lern-Achievements
  ('first_session',  'Holzaxt',        'Du hast dein erstes Abenteuer begonnen!',          '🪓', 'learning', 'sessions_completed', 1,    NULL,    NULL,               0),
  ('words_10',       'Holzschwert',    '10 Wörter gemeistert — du wirst stärker!',         '⚔️', 'learning', 'words_correct',      10,   NULL,    NULL,               0),
  ('block_a_done',   'Lederrüstung',   'Block A abgeschlossen — der Wald gehört dir!',     '🛡️', 'learning', 'block_mastered',     NULL, NULL,    NULL,               0),
  ('words_50',       'Steinschwert',   '50 Wörter richtig — ein echter Kämpfer!',          '⚔️', 'learning', 'words_correct',      50,   NULL,    NULL,               0),
  ('quests_3',       'Zaubertrank',    '3 Quests erledigt — die Magie wirkt!',             '🔮', 'learning', 'quests_completed',   3,    NULL,    NULL,               0),
  ('block_b_done',   'Eisenschwert',   'Block B gemeistert — die Wüste wartet!',           '⚔️', 'learning', 'block_mastered',     NULL, 'space', NULL,               0),
  ('block_c_done',   'Diamantschwert', 'Block C gemeistert — du bist fast unbesiegbar!',   '💎', 'learning', 'block_mastered',     NULL, 'ocean', NULL,               0),
  ('all_blocks',     'Nether-Stern',   'Alle Blöcke gemeistert — Legende!',                '🌟', 'learning', 'block_mastered',     NULL, 'dark',  NULL,               0),
  -- Streak-Achievements
  ('streak_3',       'Funken',         '3 Tage in Folge geübt — das Feuer brennt!',        '🔥', 'streak',   'streak_days',        3,    NULL,    NULL,               0),
  ('streak_7',       'Fackel',         '7 Tage am Stück — nichts kann dich aufhalten!',    '🔥', 'streak',   'streak_days',        7,    NULL,    NULL,               0),
  ('streak_14',      'Lagerfeuer',     '14 Tage — du bist ein echter Überlebenskünstler!', '🔥', 'streak',   'streak_days',        14,   NULL,    'mini_diktat_mode', 0),
  ('streak_30',      'Netherportal',   '30 Tage Streak — du hast das Netherportal geöffnet!', '🔥', 'streak', 'streak_days',       30,   'nether', NULL,              0),
  -- Secret Achievements
  ('creeper_friend', 'Creeper-Freund', 'Ein Wort macht dir Probleme — aber du gibst nicht auf!', '🐛', 'special', 'same_word_wrong',            10, NULL, NULL, 1),
  ('eagle_eye',      'Adlerauge',      '20 Wörter in Folge beim ersten Versuch — unglaublich!',  '🦅', 'special', 'correct_streak_first_try',   20, NULL, NULL, 1),
  ('slow_learner',   'Langsam aber sicher', 'Du hörst genau hin — das ist deine Stärke!',        '🐢', 'special', 'tts_slow_count',             50, NULL, NULL, 1);

-- ── Zusätzliche Abenteuer (Custom Adventures) ──────────────────────────────

CREATE TABLE IF NOT EXISTS custom_adventures (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  child_id     INTEGER NOT NULL REFERENCES users(id),
  created_by   INTEGER NOT NULL REFERENCES users(id),
  title        VARCHAR(200) NOT NULL DEFAULT 'Schulaufgabe',
  school_date  DATE NULL,
  scheduled_date DATE NOT NULL,
  status       TEXT CHECK(status IN ('pending','active','completed','cancelled')) DEFAULT 'pending',
  diktat_generated INTEGER DEFAULT 0,
  ai_notes     TEXT NULL,
  created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
  completed_at DATETIME NULL
);

CREATE TABLE IF NOT EXISTS custom_adventure_words (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  adventure_id INTEGER NOT NULL REFERENCES custom_adventures(id) ON DELETE CASCADE,
  word         VARCHAR(200) NOT NULL,
  order_index  INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS custom_adventure_sentences (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  adventure_id INTEGER NOT NULL REFERENCES custom_adventures(id) ON DELETE CASCADE,
  sentence     TEXT NOT NULL,
  order_index  INTEGER NOT NULL DEFAULT 0
);

-- Block 8 — Motivations-Features
CREATE TABLE IF NOT EXISTS parent_messages (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  child_id   INTEGER NOT NULL REFERENCES users(id),
  admin_id   INTEGER NOT NULL REFERENCES users(id),
  message    TEXT NOT NULL,
  emoji      VARCHAR(10) DEFAULT '💌',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  seen_at    DATETIME NULL
);

CREATE TABLE IF NOT EXISTS family_goals (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  child_id     INTEGER NOT NULL REFERENCES users(id),
  admin_id     INTEGER NOT NULL REFERENCES users(id),
  title        VARCHAR(200) NOT NULL,
  goal_type    TEXT CHECK(goal_type IN ('sessions','quests','streak')) DEFAULT 'sessions',
  goal_value   INTEGER NOT NULL,
  period       TEXT CHECK(period IN ('week','month','alltime')) DEFAULT 'week',
  reward_text  TEXT NULL,
  status       TEXT CHECK(status IN ('active','completed','cancelled')) DEFAULT 'active',
  created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
  completed_at DATETIME NULL,
  period_start DATE NOT NULL
);
