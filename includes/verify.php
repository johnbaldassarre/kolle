<?php
/**
 * Level-of-play verifier.
 *
 * Given a player's profile link (Elite Prospects, a league player page, etc.)
 * it fetches the page, or uses text an admin pasted from it, and checks:
 *   - the player's name (and, for Elite Prospects, the name in the URL)
 *   - the player's birthdate / birth year
 *   - which leagues appear in the season-by-season stats, using the keyword
 *     list of each Level of Play (Admin > Levels of Play)
 *   - whether the level the player claimed is among them, especially in the
 *     most recent season
 * It returns a 0-100 confidence score, a verdict and an itemised checklist.
 * The admin always makes the final call; the score is a guide only.
 */
declare(strict_types=1);

const VERIFY_MAX_BYTES = 3_000_000;

/** Run a full check for one player row (players JOIN users). */
function verify_player(array $p, ?string $pastedText = null): array
{
    $result = [
        'checked_at'     => date('Y-m-d H:i:s'),
        'source'         => 'url-only',
        'fetched'        => false,
        'http_status'    => null,
        'error'          => null,
        'checks'         => [],
        'leagues_found'  => [],
        'recent_season'  => null,
        'recent_leagues' => [],
        'score'          => 0,
        'verdict'        => 'unavailable',
    ];
    $score  = 0;
    $levels = levels(false);
    $claimed = null;
    foreach ($levels as $l) {
        if ((int)$l['id'] === (int)($p['level_id'] ?? 0)) {
            $claimed = $l;
        }
    }
    $url = (string)($p['profile_url'] ?? '');

    // ---- 1. Identity from the URL itself (works even if the site blocks us)
    $first = verify_fold((string)$p['first_name'], true);
    $last  = verify_fold((string)$p['last_name'], true);
    if (preg_match('#eliteprospects\.com/player/(\d+)/([a-z0-9\-]+)#i', $url, $m)) {
        $slug = strtolower($m[2]);
        $want = preg_replace('/[^a-z0-9]+/', '-', $first . ' ' . $last);
        $lastSlug = preg_replace('/[^a-z0-9]+/', '-', $last);
        if ($slug === $want) {
            $score += 15;
            $result['checks'][] = verify_check('pass', 'Name in Elite Prospects link', "Link is for \"$slug\" (player #{$m[1]}).");
        } elseif (str_contains($slug, $lastSlug)) {
            $score += 8;
            $result['checks'][] = verify_check('warn', 'Name in Elite Prospects link', "Link is for \"$slug\"; last name matches but first name differs (nickname?).");
        } else {
            $result['checks'][] = verify_check('fail', 'Name in Elite Prospects link', "Link is for \"$slug\", expected \"$want\".");
        }
    }

    // ---- 2. Get the page text
    $text = '';
    if ($pastedText !== null && trim($pastedText) !== '') {
        $text = verify_html_to_text($pastedText);
        $result['source'] = 'pasted';
    } elseif ($url !== '') {
        $fetch = verify_fetch($url);
        $result['http_status'] = $fetch['status'];
        if ($fetch['ok']) {
            $text = verify_html_to_text($fetch['body']);
            $result['source']  = 'fetched';
            $result['fetched'] = true;
            if (preg_match('/just a moment|enable javascript and cookies|attention required|access denied/i', substr($text, 0, 2000)) && strlen($text) < 4000) {
                $text = '';
                $result['error'] = 'The site returned a bot-protection page instead of the profile.';
            }
        } else {
            $result['error'] = $fetch['error'];
        }
    } else {
        $result['error'] = 'No profile link was provided.';
    }

    if ($text === '') {
        $result['checks'][] = verify_check('info', 'Profile page', ($result['error'] ?? 'Could not read the page.')
            . ' Open the link, copy the whole page (Ctrl+A, Ctrl+C) and paste it into "Check pasted page text".');
        $result['score'] = $score;
        return $result;
    }

    $ascii = verify_fold($text, false);
    $lower = strtolower($ascii);

    // ---- 3. Name on page
    $firstOk = $first !== '' && preg_match('/\b' . preg_quote($first, '/') . '\b/', $lower);
    $lastOk  = $last !== '' && preg_match('/\b' . preg_quote($last, '/') . '\b/', $lower);
    if ($firstOk && $lastOk) {
        $score += 25;
        $result['checks'][] = verify_check('pass', 'Player name on page', 'Found "' . $p['first_name'] . ' ' . $p['last_name'] . '".');
    } elseif ($lastOk) {
        $score += 12;
        $result['checks'][] = verify_check('warn', 'Player name on page', 'Last name found, first name not found.');
    } else {
        $result['checks'][] = verify_check('fail', 'Player name on page', 'Name not found on the page. Is this the right player?');
    }

    // ---- 4. Birthdate
    if (!empty($p['birthdate'])) {
        $ts = strtotime($p['birthdate']);
        $formats = array_unique([
            date('Y-m-d', $ts), date('M j, Y', $ts), date('F j, Y', $ts), date('j M Y', $ts), date('j F Y', $ts),
            date('m/d/Y', $ts), date('n/j/Y', $ts), date('d.m.Y', $ts), date('d/m/Y', $ts), date('Y/m/d', $ts),
        ]);
        $dobFound = false;
        foreach ($formats as $f) {
            if (stripos($ascii, $f) !== false) {
                $dobFound = $f;
                break;
            }
        }
        $year = date('Y', $ts);
        if ($dobFound) {
            $score += 15;
            $result['checks'][] = verify_check('pass', 'Birthdate', "Exact birthdate found ($dobFound).");
        } elseif (preg_match('/(born|birth|b\.|dob|date of birth|age)[^0-9]{0,40}' . $year . '/i', $ascii) || preg_match('/"birthDate"\s*:\s*"' . $year . '/', $text)) {
            $score += 8;
            $result['checks'][] = verify_check('warn', 'Birthdate', "Birth year $year found, exact date not confirmed.");
        } else {
            $result['checks'][] = verify_check('info', 'Birthdate', 'Birthdate not found on page (many league pages do not show it).');
        }
    }

    // ---- 5. Leagues: in the season-by-season stats vs anywhere on the page
    $windows = verify_season_windows($ascii);
    $allHits = verify_find_levels($ascii, $levels);
    $statHits = [];
    $recentHits = [];
    $recentSeason = null;
    if ($windows) {
        $recentSeason = max(array_keys($windows));
        foreach ($windows as $season => $chunks) {
            foreach ($chunks as $chunk) {
                foreach (verify_find_levels($chunk, $levels) as $lid => $hit) {
                    $statHits[$lid] = ($statHits[$lid] ?? 0) + $hit['count'];
                    if ($season === $recentSeason) {
                        $recentHits[$lid] = true;
                    }
                }
            }
        }
    }
    $byId = [];
    foreach ($levels as $l) {
        $byId[(int)$l['id']] = $l;
    }
    foreach ($allHits as $lid => $hit) {
        $result['leagues_found'][] = [
            'level'    => $byId[$lid]['name'],
            'rank'     => (int)$byId[$lid]['rank_value'],
            'keywords' => implode(', ', array_slice(array_unique($hit['keywords']), 0, 5)),
            'count'    => $hit['count'],
            'in_stats' => isset($statHits[$lid]),
            'recent'   => isset($recentHits[$lid]),
            'claimed'  => $claimed && (int)$claimed['id'] === $lid,
        ];
    }
    usort($result['leagues_found'], fn($a, $b) => [$b['in_stats'], $b['rank']] <=> [$a['in_stats'], $a['rank']]);
    if ($recentSeason) {
        $result['recent_season'] = verify_season_label($recentSeason);
        $result['recent_leagues'] = array_values(array_map(fn($lid) => $byId[$lid]['name'], array_keys($recentHits)));
    }

    if ($claimed) {
        $cid = (int)$claimed['id'];
        if (isset($recentHits[$cid])) {
            $score += 45;
            $result['checks'][] = verify_check('pass', 'Claimed level: ' . $claimed['name'], 'Found in the most recent season (' . $result['recent_season'] . ').');
        } elseif (isset($statHits[$cid])) {
            $score += 35;
            $result['checks'][] = verify_check('pass', 'Claimed level: ' . $claimed['name'], 'Found in the player\'s season stats (not the most recent season).');
        } elseif (isset($allHits[$cid])) {
            $score += 15;
            $result['checks'][] = verify_check('warn', 'Claimed level: ' . $claimed['name'], 'Mentioned on the page but not tied to a season of stats. Check manually.');
        } else {
            $result['checks'][] = verify_check('fail', 'Claimed level: ' . $claimed['name'], 'No league from this level was found on the page.');
        }

        // Highest level seen in the stats compared with the claim.
        if ($statHits) {
            $top = null;
            foreach (array_keys($statHits) as $lid) {
                if (!$top || $byId[$lid]['rank_value'] > $top['rank_value']) {
                    $top = $byId[$lid];
                }
            }
            $diff = (int)$top['rank_value'] - (int)$claimed['rank_value'];
            if ($diff > 5) {
                $result['checks'][] = verify_check('warn', 'Highest level in stats', 'Player has also played ' . $top['name'] . ', which is higher than the level claimed.');
            } elseif ($diff < -5 && !isset($statHits[$cid])) {
                $score -= 10;
                $result['checks'][] = verify_check('fail', 'Highest level in stats', 'Highest level found is ' . $top['name'] . ', lower than the level claimed.');
            }
        }
    }

    // ---- 6. League name / team typed by the player
    if (!empty($p['league_name'])) {
        $ln = strtolower(verify_fold((string)$p['league_name'], false));
        if (strlen($ln) >= 2 && preg_match('/(?<![a-z0-9])' . preg_replace('/\s+/', '\s+', preg_quote($ln, '/')) . '(?![a-z0-9])/', $lower)) {
            $score += 5;
            $result['checks'][] = verify_check('pass', 'League typed by player', '"' . $p['league_name'] . '" appears on the page.');
        } else {
            $result['checks'][] = verify_check('info', 'League typed by player', '"' . $p['league_name'] . '" not found word-for-word.');
        }
    }
    if (!empty($p['team_name'])) {
        $tn = strtolower(verify_fold((string)$p['team_name'], false));
        $words = array_filter(preg_split('/\s+/', $tn), fn($w) => strlen($w) >= 4);
        $hits = array_filter($words, fn($w) => str_contains($lower, $w));
        if ($words && count($hits) === count($words)) {
            $score += 5;
            $result['checks'][] = verify_check('pass', 'Team', '"' . $p['team_name'] . '" appears on the page.');
        } else {
            $result['checks'][] = verify_check('info', 'Team', '"' . $p['team_name'] . '" not found on the page.');
        }
    }

    $score = max(0, min(100, $score));
    $result['score']   = $score;
    $result['verdict'] = $score >= 70 ? 'match' : ($score >= 40 ? 'review' : 'mismatch');
    return $result;
}

function verify_check(string $status, string $label, string $detail): array
{
    return ['status' => $status, 'label' => $label, 'detail' => $detail];
}

function verify_verdict_label(string $verdict): string
{
    return [
        'match'       => 'Likely match',
        'review'      => 'Needs review',
        'mismatch'    => 'Possible mismatch',
        'unavailable' => 'Could not read profile',
    ][$verdict] ?? $verdict;
}

/** Save a result onto the player row. */
function verify_store(int $userId, array $result): void
{
    db()->prepare('UPDATE players SET auto_check_score = ?, auto_check_result = ?, auto_checked_at = NOW() WHERE user_id = ?')
        ->execute([$result['score'], json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $userId]);
}

// ---------------------------------------------------------------------------
// Text helpers
// ---------------------------------------------------------------------------

/** Transliterate to ASCII (é -> e, ä -> a, etc). Optionally lowercase. */
function verify_fold(string $s, bool $lower): string
{
    if (function_exists('transliterator_transliterate')) {
        $t = transliterator_transliterate('Any-Latin; Latin-ASCII', $s);
        if ($t !== false) {
            $s = $t;
        }
    } else {
        $map = ['à'=>'a','á'=>'a','â'=>'a','ã'=>'a','ä'=>'a','å'=>'a','æ'=>'ae','ç'=>'c','è'=>'e','é'=>'e','ê'=>'e','ë'=>'e',
                'ì'=>'i','í'=>'i','î'=>'i','ï'=>'i','ñ'=>'n','ò'=>'o','ó'=>'o','ô'=>'o','õ'=>'o','ö'=>'o','ø'=>'o','ù'=>'u',
                'ú'=>'u','û'=>'u','ü'=>'u','ý'=>'y','ÿ'=>'y','š'=>'s','ž'=>'z','č'=>'c','ř'=>'r','ě'=>'e','ů'=>'u','ł'=>'l',
                'À'=>'A','Á'=>'A','Â'=>'A','Ä'=>'A','Å'=>'A','Ç'=>'C','È'=>'E','É'=>'E','Ê'=>'E','Ë'=>'E','Í'=>'I','Î'=>'I',
                'Ñ'=>'N','Ó'=>'O','Ô'=>'O','Ö'=>'O','Ø'=>'O','Ú'=>'U','Ü'=>'U','Š'=>'S','Ž'=>'Z','Č'=>'C','Ř'=>'R','Ł'=>'L',
                '–'=>'-','—'=>'-','’'=>"'",'‘'=>"'"];
        $s = strtr($s, $map);
        $s = preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', ' ', $s);
    }
    $s = str_replace(["\u{2013}", "\u{2014}"], '-', $s);
    return $lower ? strtolower(trim($s)) : $s;
}

/** Strip HTML but keep the text of JSON data blocks (used by modern sites like Elite Prospects). */
function verify_html_to_text(string $html): string
{
    if (!preg_match('/<[a-z!\/][^>]*>/i', $html)) {
        return preg_replace('/[ \t]+/', ' ', $html); // already plain text (pasted)
    }
    $json = '';
    if (preg_match_all('#<script[^>]*type=["\']application/(?:ld\+)?json["\'][^>]*>(.*?)</script>#is', $html, $m)) {
        foreach ($m[1] as $block) {
            $decoded = json_decode(html_entity_decode($block), true);
            $json .= ' ' . ($decoded !== null ? implode(' ', verify_flatten($decoded)) : $block);
        }
    }
    $html = preg_replace('#<(script|style|svg|noscript)\b[^>]*>.*?</\1>#is', ' ', $html);
    $html = preg_replace('#<(br|/p|/div|/tr|/li|/h\d|/td|/th)\b[^>]*>#i', "$0\n", $html);
    $html = str_replace('<', ' <', $html);
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/[ \t\x{00A0}]+/u', ' ', $text . "\n" . $json);
    return preg_replace("/\n\s*\n+/", "\n", $text);
}

function verify_flatten($data, int $depth = 0): array
{
    if ($depth > 12) {
        return [];
    }
    if (is_array($data)) {
        $out = [];
        foreach ($data as $k => $v) {
            if (is_array($v)) {
                $out = array_merge($out, verify_flatten($v, $depth + 1));
                $out[] = "\n";
            } elseif (is_scalar($v) && $v !== '') {
                $out[] = (string)$v;
            }
        }
        return $out;
    }
    return is_scalar($data) ? [(string)$data] : [];
}

/**
 * Find text that follows each season label ("2024-25", "2024-2025", "2024/25").
 * Returns [startYear => [chunk, ...]] so leagues can be tied to seasons.
 */
function verify_season_windows(string $text): array
{
    $out = [];
    $maxYear = (int)date('Y') + 1;
    if (preg_match_all('/(?<!\d)((?:19|20)\d{2})\s*[-\/]\s*((?:19|20)?\d{2})(?!\d)/', $text, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
        $seasons = [];
        foreach ($m as $match) {
            $y1 = (int)$match[1][0];
            $y2 = (int)substr($match[2][0], -2);
            if ($y1 > $maxYear || ($y1 + 1) % 100 !== $y2) {
                continue; // not a hockey season (e.g. a date range)
            }
            $seasons[] = [$y1, $match[0][1], $match[0][1] + strlen($match[0][0])];
        }
        // Each window runs until the next season label (max 160 chars) so rows don't bleed together.
        foreach ($seasons as $i => [$year, , $start]) {
            $end = isset($seasons[$i + 1]) ? min($seasons[$i + 1][1], $start + 160) : $start + 160;
            $out[$year][] = substr($text, $start, max(0, $end - $start));
        }
    }
    return $out;
}

function verify_season_label(int $y): string
{
    return $y . '-' . substr((string)($y + 1), -2);
}

/**
 * Match every level's keywords against the text.
 * Short all-caps keywords (OHL, AAA, U16) are matched case-sensitively so they
 * do not fire on ordinary words; longer names are case-insensitive. Overlapping
 * hits keep the longest keyword (so "U16 AAA" beats "U16", "AAA" beats "AA").
 * Returns [level_id => ['count' => n, 'keywords' => [...]]].
 */
function verify_find_levels(string $text, array $levels): array
{
    $hits = [];
    foreach ($levels as $l) {
        foreach (array_filter(array_map('trim', explode(',', (string)$l['keywords']))) as $kw) {
            $isAcronym = strlen($kw) <= 7 && preg_match('/^[A-Z0-9 \-]+$/', $kw);
            $pattern = '/(?<![A-Za-z0-9])' . preg_replace('/\\\\? /', '\s+', preg_quote($kw, '/')) . '(?![A-Za-z0-9])/' . ($isAcronym ? '' : 'i');
            if (preg_match_all($pattern, $text, $m, PREG_OFFSET_CAPTURE)) {
                foreach ($m[0] as [$str, $pos]) {
                    $hits[] = ['level' => (int)$l['id'], 'kw' => $kw, 'pos' => $pos, 'len' => strlen($str)];
                }
            }
        }
    }
    usort($hits, fn($a, $b) => $b['len'] <=> $a['len']);
    $taken = [];
    $out = [];
    foreach ($hits as $h) {
        foreach ($taken as [$s, $e]) {
            if ($h['pos'] < $e && $h['pos'] + $h['len'] > $s) {
                continue 2;
            }
        }
        $taken[] = [$h['pos'], $h['pos'] + $h['len']];
        $out[$h['level']]['count'] = ($out[$h['level']]['count'] ?? 0) + 1;
        $out[$h['level']]['keywords'][] = $h['kw'];
    }
    return $out;
}

// ---------------------------------------------------------------------------
// Safe fetching (blocks requests to private/internal addresses)
// ---------------------------------------------------------------------------

function verify_fetch(string $url, int $redirects = 4): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'status' => null, 'body' => '', 'error' => 'The PHP curl extension is not installed on this server.'];
    }
    for ($i = 0; $i <= $redirects; $i++) {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = $parts['host'] ?? '';
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return ['ok' => false, 'status' => null, 'body' => '', 'error' => 'Only http(s) links can be checked.'];
        }
        $port = (int)($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        $ip = verify_public_ip($host);
        if (!$ip) {
            return ['ok' => false, 'status' => null, 'body' => '', 'error' => "Could not resolve $host to a public address."];
        }
        $body = '';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADER         => false,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_RESOLVE        => ["$host:$port:$ip"],
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_ENCODING       => '',
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
            CURLOPT_HTTPHEADER     => ['Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8', 'Accept-Language: en-US,en;q=0.9'],
            CURLOPT_WRITEFUNCTION  => function ($ch, $chunk) use (&$body) {
                $body .= $chunk;
                return strlen($body) > VERIFY_MAX_BYTES ? 0 : strlen($chunk);
            },
        ]);
        curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $location = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        $err = curl_error($ch);
        curl_close($ch);

        if ($status >= 300 && $status < 400 && $location) {
            $url = $location;
            continue;
        }
        if ($status === 200 && $body !== '') {
            return ['ok' => true, 'status' => $status, 'body' => $body, 'error' => null];
        }
        $msg = $status ? "The site answered with HTTP $status" . ($status === 403 ? ' (it may block automated checks)' : '') . '.' : 'Could not connect: ' . $err;
        return ['ok' => false, 'status' => $status ?: null, 'body' => '', 'error' => $msg];
    }
    return ['ok' => false, 'status' => null, 'body' => '', 'error' => 'Too many redirects.'];
}

function verify_public_ip(string $host): ?string
{
    $host = trim($host, '[]');
    $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
    foreach ($ips as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return null; // refuse hosts that point at internal addresses
        }
    }
    return $ips[0] ?? null;
}
