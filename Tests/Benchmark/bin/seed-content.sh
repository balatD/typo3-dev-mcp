#!/usr/bin/env bash
#
# Seeds the live state the F1/F3 benchmark tasks interrogate: the fixture site
# set attached to the site, a second language, a small page tree, and content
# using the fixture's CType and FlexForm plugin.
#
# Written straight to the database rather than through the DataHandler — this is
# fixture data, so determinism and a readable diff matter more than DataHandler
# side effects, and every value here is also the expected value in an oracle.
#
# Usage: seed-content.sh

set -euo pipefail

BENCH_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# shellcheck disable=SC1091
source "$BENCH_DIR/.bench-env"

SITE_CONFIG="$BENCH_ROOT/config/sites/main/config.yaml"

# --- site config: attach the set and a second language ----------------------

cat > "$SITE_CONFIG" <<EOF
base: '$SITE_URL'
dependencies:
  - balatd/bench-fixture
errorHandling:
  -
    errorCode: 404
    errorHandler: Page
    errorContentSource: 't3://page?uid=6'
languages:
  -
    title: English
    enabled: true
    languageId: 0
    base: /
    locale: en_US.UTF-8
    navigationTitle: English
    flag: us
  -
    title: German
    enabled: true
    languageId: 1
    base: /de/
    locale: de_DE.UTF-8
    navigationTitle: Deutsch
    flag: de
    fallbackType: strict
rootPageId: 1
routes: {  }
settings:
  benchFixture:
    itemsPerPage: 24
    headerColor: '#c0392b'
EOF

# --- page tree + content ----------------------------------------------------

# Written to a file inside the project and sourced in the container: `ddev mysql`
# swallows heredoc stdin and prints its own option dump instead of executing.
cat > "$BENCH_ROOT/.seed.sql" <<'SQL'
-- `typo3 setup --create-site` leaves a legacy sys_template that fights the site
-- set two ways, and both are silent:
--   clear=3 discards constants AND setup, wiping everything the set provides;
--   include_static_file pulls fluid_styled_content's TypoScript in AFTER the
--   set, re-declaring lib.contentElement.templateRootPaths from key 0 and
--   dropping the set's .200 entry.
-- Either one leaves the fixture looking installed while none of its TypoScript
-- is live — benchfixture_card renders as "no rendering definition", or resolves
-- against fluid_styled_content's paths only. The set already depends on
-- typo3/fluid-styled-content, so the static includes are redundant here.
UPDATE sys_template SET clear = 0, include_static_file = '' WHERE root = 1;

-- Every seeded table is cleared first so the script is re-runnable.
DELETE FROM pages WHERE uid > 1;
DELETE FROM tt_content;
DELETE FROM tx_benchfixture_item;

INSERT INTO pages (uid, pid, title, nav_title, doktype, slug, sorting, perms_userid, perms_groupid, perms_user, perms_group, perms_everybody, is_siteroot, sys_language_uid, l10n_parent)
VALUES
    (2, 1, 'Products',   'Products',  1, '/products',   256,  1, 0, 31, 27, 0, 0, 0, 0),
    (3, 2, 'Hardware',   'Hardware',  1, '/products/hardware', 256, 1, 0, 31, 27, 0, 0, 0, 0),
    (4, 2, 'Software',   'Software',  1, '/products/software', 512, 1, 0, 31, 27, 0, 0, 0, 0),
    (5, 1, 'Company',    'Company',   1, '/company',    512,  1, 0, 31, 27, 0, 0, 0, 0),
    (6, 1, 'Not found',  'Not found', 1, '/not-found',  768,  1, 0, 31, 27, 0, 0, 0, 0),
    (7, 1, 'Hidden page','Hidden',    1, '/hidden',    1024,  1, 0, 31, 27, 0, 0, 0, 0),
    (8, 1, 'Produkte',   'Produkte',  1, '/de/produkte', 256, 1, 0, 31, 27, 0, 0, 1, 2);

UPDATE pages SET hidden = 1 WHERE uid = 7;

INSERT INTO tt_content (uid, pid, CType, header, bodytext, colPos, sorting, sys_language_uid, tx_benchfixture_teaser, tx_benchfixture_level, tx_benchfixture_related)
VALUES
    (1, 1, 'text',                'Welcome',          '<p>Benchmark root page.</p>', 0, 256, 0, '', 0, 0),
    (2, 2, 'benchfixture_card',   'Featured product', '<p>Card body.</p>',           0, 256, 0, 'A teaser on the card', 30, 3),
    (3, 2, 'benchfixture_card',   'Second card',      '<p>More body.</p>',           0, 512, 0, 'Another teaser',       10, 4),
    (4, 3, 'textmedia',           'Hardware intro',   '<p>Hardware.</p>',            0, 256, 0, '', 0, 0),
    (5, 5, 'benchfixture_card',   'About us',         '<p>Company.</p>',             2, 256, 0, 'Sidebar teaser',       20, 0);

INSERT INTO tt_content (uid, pid, CType, header, colPos, sorting, sys_language_uid, pi_flexform)
VALUES
    (6, 4, 'benchfixture_listing', 'Software listing', 0, 256, 0,
'<?xml version="1.0" encoding="utf-8" standalone="yes" ?>
<T3FlexForms>
    <data>
        <sheet index="sDEF">
            <language index="lDEF">
                <field index="settings.source"><value index="vDEF">featured</value></field>
                <field index="settings.limit"><value index="vDEF">9</value></field>
                <field index="settings.startingPoint"><value index="vDEF">3,4</value></field>
            </language>
        </sheet>
        <sheet index="sAppearance">
            <language index="lDEF">
                <field index="settings.layout"><value index="vDEF">list</value></field>
                <field index="settings.showTeaser"><value index="vDEF">0</value></field>
            </language>
        </sheet>
    </data>
</T3FlexForms>');

INSERT INTO tx_benchfixture_item (uid, pid, title, subtitle, weight, is_featured)
VALUES
    (1, 2, 'Alpha widget', 'The first one',  10, 1),
    (2, 2, 'Beta widget',  'The second one', 20, 0),
    (3, 2, 'Gamma widget', 'The third one',  30, 1);
SQL

ddev -p "$PROJECT_NAME" exec bash -c 'mysql -h db -u db -pdb db < /var/www/html/.seed.sql'
rm -f "$BENCH_ROOT/.seed.sql"

ddev -p "$PROJECT_NAME" exec vendor/bin/typo3 cache:flush >/dev/null

echo "seeded: 8 pages, 6 content elements, 3 fixture items, set attached"
