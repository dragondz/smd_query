/**
 * smd_query
 *
 * A Textpattern CMS plugin for interacting with the Txp database:
 *  -> Run arbitrary SQL statements to query, insert, update, delete, etc
 *  -> Process each returned row through a Form/container
 *  -> Optionally filter the URL input using regular expressions, for safety
 *  -> Supports <txp:else />
 *  -> Results can be paged
 *
 * @author Stef Dawson
 * @link   https://stefdawson.com/
 * @todo   preparse=1 kills the ability to replace {tag} with
 *         <txp:smd_query_info item="tag" /> because the act of parsing the
 *         container with {tags} in it and then replacing them with real tags
 *         doesn't execute the content: it needs a second parse() which is slower.
 */
if (class_exists('\Textpattern\Tag\Registry')) {
    Txp::get('\Textpattern\Tag\Registry')
        ->register('smd_query')
        ->register('smd_query_info')
        ->register('smd_if_prev')
        ->register('smd_if_next');
}

/**
 * smd_query tag
 *
 * Perform a database query and return results in an iterable/parsable format.
 * @param  array $atts   Tag attributes
 * @param  string $thing Tag container content
 */
function smd_query($atts, $thing = null)
{
    global $pretext, $smd_query_pginfo, $thispage, $thisarticle, $thisimage, $thisfile, $thislink, $smd_query_data;

    extract(lAtts(array(
        'column'       => '',
        'table'        => '',
        'where'        => '',
        'query'        => '',
        'form'         => '',
        'pageform'     => '',
        'pagevar'      => 'pg',
        'pagepos'      => 'below',
        'colsform'     => '',
        'escape'       => '',
        'strictfields' => '0',
        'preparse'     => '0', // 0 = {replace} then parse, 1 = parse then {replace}
        'populate'     => '', // one of article, image, file, or link
        'raw_vals'     => '0',
        'urlfilter'    => '',
        'urlreplace'   => '',
        'defaults'     => '',
        'delim'        => ',',
        'paramdelim'   => ':',
        'silent'       => '0',
        'mode'         => 'auto', // auto chooses one of input (INSERT/UPDATE) or output (QUERY)
        'count'        => 'up',
        'var_prefix'   => 'smd_',
        'limit'        => 0,
        'offset'       => 0,
        'hashsize'     => '6:5',
        'label'        => '',
        'labeltag'     => '',
        'wraptag'      => '',
        'break'        => '',
        'class'        => '',
        'breakclass'   => '',
        'html_id'      => '',
        'debug'        => '0',
    ), $atts));

    // Grab the form or embedded $thing.
    $falsePart = EvalElse($thing, 0);

    $thing = ($form) ? fetch_form($form) . (($falsePart) ? '<txp:else />' . $falsePart : '') : (($thing) ? $thing : '');
    $colsform = (empty($colsform)) ? '' : fetch_form($colsform);
    $pagebit = array();

    if ($pageform) {
        $pagePosAllowed = array("below", "above");
        $paging = 1;
        $pageform = fetch_form($pageform);
        $pagepos = str_replace('smd_', '', $pagepos);
        $pagepos = do_list($pagepos, $delim);

        foreach ($pagepos as $pageitem) {
            $pagebit[] = (in_array($pageitem, $pagePosAllowed)) ? $pageitem : $pagePosAllowed[0];
        }
    }

    // Make a unique hash value for this instance so the queries
    // can be paged independently.
    $uniq = '';
    $md5 = md5($column.$table.$where.$query.$defaults);
    list($hashLen, $hashSkip) = explode(':', $hashsize);

    for ($idx = 0, $cnt = 0; $cnt < $hashLen; $cnt++, $idx = (($idx+$hashSkip) % strlen($md5))) {
        $uniq .= $md5[$idx];
    }

    $pagevar = ($pagevar == 'SMD_QUERY_UNIQUE_ID') ? $uniq : $pagevar;
    $urlfilter = (!empty($urlfilter)) ? do_list($urlfilter, $delim) : '';
    $urlreplace = (!empty($urlreplace)) ? do_list($urlreplace, $delim) : '';

    if ($debug > 0) {
        echo "++ URL FILTERS ++";
        dmp($urlfilter);
        dmp($urlreplace);
    }

    // Process any defaults.
    $spc = ($strictfields) ? 0 : 1;
    $defaults = do_list($defaults, $delim);
    $dflts = array();

    foreach ($defaults as $item) {
        $item = do_list($item, $paramdelim);
        if ($item[0] == '') continue;
        if (count($item) == 2) {
            $dflts[$item[0]] = smd_query_parse($item[1], array(''), array(''), array(''), $spc);
        }
    }

    if ($debug > 0) {
        echo "++ DEFAULTS ++";
        dmp($dflts);
    }

    // Get a list of fields to escape.
    $escapes = do_list($escape, $delim);

    foreach ($escapes as $idx => $val) {
        if ($val == '') {
            unset($escapes[$idx]);
        }
    }

    $rs = array();
    $out = array();
    $colout = $finalout = array();
    $pageout = array();

    // query overrides column/table/where.
    if ($query) {
        $query = smd_query_parse($query, $dflts, $urlfilter, $urlreplace, $spc);
        $mode = ($mode == 'auto') ? ((preg_match('/(select|show)/i', $query)) ? 'output' : 'input') : $mode;
        if ($mode == 'input') {
            $rs = ($silent) ? @safe_query($query, $debug) : safe_query($query, $debug);
        } else {
            $rs = ($silent) ? @getRows($query, $debug) : getRows($query, $debug);
        }
    } else {
        if ($column && $table) {
            // TODO: Perhaps doSlash() these? Or strip_tags?
            $column = smd_query_parse($column, $dflts, $urlfilter, $urlreplace, $spc);
            $table = smd_query_parse($table, $dflts, $urlfilter, $urlreplace, $spc);
            $where = smd_query_parse($where, $dflts, $urlfilter, $urlreplace, $spc);
            $where = ($where) ? $where : "1=1";
            $mode = 'output';
            $rs = ($silent) ? @safe_rows($column, $table, $where, $debug) : safe_rows($column, $table, $where, $debug);
        } else {
            trigger_error("You must specify at least 1 'column' and a 'table'.");
        }
    }

    if ($mode == 'output') {
      if ($rs) {
        $numrows = count($rs);
      } else {
        $numrows = 0;
      }
        $truePart = EvalElse($thing, 1);

        if ($rs) {
            if ($debug > 1) {
                echo "++ QUERY RESULT SET ++";
                dmp($numrows . " ROWS");
                dmp($rs);
            }

            if ($limit > 0) {
                $safepage = $thispage;
                $total = $numrows - $offset;
                $numPages = ceil($total/$limit);
                $pg = (!gps($pagevar)) ? 1 : gps($pagevar);
                $pgoffset = $offset + (($pg - 1) * $limit);
                // Send paging info to txp:newer and txp:older.
                $pageout['pg'] = $pg;
                $pageout['numPages'] = $numPages;
                $pageout['s'] = $pretext['s'];
                $pageout['c'] = $pretext['c'];
                $pageout['grand_total'] = $numrows;
                $pageout['total'] = $total;
                $thispage = $pageout;
            } else {
                $pgoffset = $offset;
            }

            $rs = array_slice($rs, $pgoffset, (($limit==0) ? 99999 : $limit));
            $pagerows = count($rs);

            $replacements = $repagements = $colreplacements = array();
            $page_rowcnt = ($count == "up") ? 0 : $pagerows-1;
            $qry_rowcnt = ($count == "up") ? $pgoffset-$offset : $numrows-$pgoffset-1;
            $used_rowcnt = 1;
            $first_row = $qry_rowcnt + 1;

            // Preserve any external context.
            switch ($populate) {
                case 'article':
                    $safe = ($thisarticle) ? $thisarticle : array();
                    break;
                case 'image':
                    $safe = ($thisimage) ? $thisimage : array();
                    break;
                case 'file':
                    $safe = ($thisfile) ? $thisfile : array();
                    break;
                case 'link':
                    $safe = ($thislink) ? $thislink : array();
                    break;
            }

            foreach ($rs as $row) {
                foreach ($row as $colid => $val) {
                    // Construct the replacement arrays and global data used by the smd_query_info tag.
                    if ($page_rowcnt == 0 && $colsform) {
                        $colreplacements['{'.$colid.'}'] = ($raw_vals) ? $colid : '<txp:smd_query_info type="col" item="' . $colid. '" />';
                        $smd_query_data['col'][$colid] = $colid;
                    }

                    // Mitigate injection attacks by using an actual Txp tag instead of the raw value
                    // Note the type is specified in case the default is ever altered.
                    $escval = (in_array($colid, $escapes) ? htmlspecialchars($val, ENT_QUOTES) : $val);
                    $replacements['{'.$colid.'}'] = ($raw_vals) ? $escval : '<txp:smd_query_info type="field" item="' . $colid. '" />';
                    $smd_query_data['field'][$colid] = $escval;

                    if ($page_rowcnt == (($count == "up") ? $pagerows-1 : 0) && $pageform && $limit>0) {
                        $prevpg = (($pg-1) > 0) ? $pg-1 : '';
                        $nextpg = (($pg+1) <= $numPages) ? $pg+1 : '';
                        $rowprev = $prevpg ? $limit : 0;
                        $rownext = (($nextpg) ? ((($qry_rowcnt+$limit+1) > $total) ? $total-$qry_rowcnt-1 : $limit) : 0);

                        // These values are all generated by the plugin and are just numbers, so don't need the
                        // extra protection of being output as real tags.
                        $repagements['{'.$var_prefix.'allrows}'] = $total;
                        $repagements['{'.$var_prefix.'pages}'] = $numPages;
                        $repagements['{'.$var_prefix.'prevpage}'] = $prevpg;
                        $repagements['{'.$var_prefix.'thispage}'] = $pg;
                        $repagements['{'.$var_prefix.'nextpage}'] = $nextpg;
                        $repagements['{'.$var_prefix.'row_start}'] = $first_row;
                        $repagements['{'.$var_prefix.'row_end}'] = $qry_rowcnt + 1;
                        $repagements['{'.$var_prefix.'rows_prev}'] = $rowprev;
                        $repagements['{'.$var_prefix.'rows_next}'] = $rownext;
                        $repagements['{'.$var_prefix.'query_unique_id}'] = $uniq;

                        $smd_query_data['page'][$var_prefix.'allrows'] = $total;
                        $smd_query_data['page'][$var_prefix.'pages'] = $numPages;
                        $smd_query_data['page'][$var_prefix.'prevpage'] = $prevpg;
                        $smd_query_data['page'][$var_prefix.'thispage'] = $pg;
                        $smd_query_data['page'][$var_prefix.'nextpage'] = $nextpg;
                        $smd_query_data['page'][$var_prefix.'row_start'] = $first_row;
                        $smd_query_data['page'][$var_prefix.'row_end'] = $qry_rowcnt + 1;
                        $smd_query_data['page'][$var_prefix.'rows_prev'] = $rowprev;
                        $smd_query_data['page'][$var_prefix.'rows_next'] = $rownext;
                        $smd_query_data['page'][$var_prefix.'query_unique_id'] = $uniq;
                        $smd_query_pginfo = $repagements;
                    }
                }

                $allrows = ($limit > 0) ? $total : $numrows-$pgoffset;
                $pages = ($limit > 0) ? $numPages : 1;
                $currpage = ($limit > 0) ? $pg : 1;
                $replacements['{'.$var_prefix.'allrows}'] = $allrows;
                $replacements['{'.$var_prefix.'rows}'] = $pagerows;
                $replacements['{'.$var_prefix.'pages}'] = $pages;
                $replacements['{'.$var_prefix.'thispage}'] = $currpage;
                $replacements['{'.$var_prefix.'thisindex}'] = $page_rowcnt;
                $replacements['{'.$var_prefix.'thisrow}'] = $page_rowcnt + 1;
                $replacements['{'.$var_prefix.'cursorindex}'] = $qry_rowcnt;
                $replacements['{'.$var_prefix.'cursor}'] = $qry_rowcnt + 1;
                $replacements['{'.$var_prefix.'usedrow}'] = $used_rowcnt;

                $smd_query_data['field'][$var_prefix.'allrows'] = $allrows;
                $smd_query_data['field'][$var_prefix.'rows'] = $pagerows;
                $smd_query_data['field'][$var_prefix.'pages'] = $pages;
                $smd_query_data['field'][$var_prefix.'thispage'] = $currpage;
                $smd_query_data['field'][$var_prefix.'thisindex'] = $page_rowcnt;
                $smd_query_data['field'][$var_prefix.'thisrow'] = $page_rowcnt + 1;
                $smd_query_data['field'][$var_prefix.'cursorindex'] = $qry_rowcnt;
                $smd_query_data['field'][$var_prefix.'cursor'] = $qry_rowcnt + 1;
                $smd_query_data['field'][$var_prefix.'usedrow'] = $used_rowcnt;

                if ($debug > 0) {
                    echo "++ REPLACEMENTS ++";
                    dmp($replacements);
                }

                // Attempt to set up contexts to allow TXP tags to be used.
                switch ($populate) {
                    case 'article':
                        if (function_exists('article_format_info')) {
                            article_format_info($row);
                        } else {
                            // TO REMOVE.
                            populateArticleData($row);
                        }
                        $thisarticle['is_first'] = ($page_rowcnt == 1);
                        $thisarticle['is_last'] = (($page_rowcnt + 1) == $pagerows);
                        break;
                    case 'image':
                        $thisimage = image_format_info($row);
                        break;
                    case 'file':
                        $thisfile = file_download_format_info($row);
                        break;
                    case 'link':
                        if (function_exists('link_format_info')) {
                            $thislink = link_format_info($row);
                        } else {
                            // TO REMOVE.
                            $thislink = array(
                                'id'          => $row['id'],
                                'linkname'    => $row['linkname'],
                                'url'         => $row['url'],
                                'description' => $row['description'],
                                'date'        => $row['uDate'],
                                'category'    => $row['category'],
                                'author'      => $row['author'],
                            );
                        }
                        break;
                }

                $pp = ($preparse) ? strtr(parse($truePart), $replacements) : parse(strtr($truePart, $replacements));
                $pp = trim(($raw_vals == '0') ? parse($pp) : $pp);

                if ($pp) {
                    $out[] = $pp;
                    $used_rowcnt++;
                }

                $qry_rowcnt = ($count=="up") ? $qry_rowcnt+1 : $qry_rowcnt-1;
                $page_rowcnt = ($count=="up") ? $page_rowcnt+1 : $page_rowcnt-1;
            }

            if ($out) {
                if ($colreplacements) {
                    $colout[] = ($preparse) ? parse(strtr(parse($colsform), $colreplacements)) : parse(strtr($colsform, $colreplacements));
                }

                if ($repagements) {
                    // Doesn't need an extra parse in the preparse phase because none of the replacements come
                    // from outside the plugin so they are used {verbatim}.
                    $pageout = ($preparse) ? strtr(parse($pageform), $repagements) : parse(strtr($pageform, $repagements));
                }

                // Make up the final output.
                if (in_array("above", $pagebit)) {
                    $finalout[] = $pageout;
                }

                $finalout[] = doLabel($label, $labeltag).doWrap(array_merge($colout, $out), $wraptag, $break, $class, $breakclass, '', '', $html_id);

                if (in_array("below", $pagebit)) {
                    $finalout[] = $pageout;
                }

                // Restore the paging outside the plugin container.
                if ($limit > 0) {
                    $thispage = $safepage;
                }

                // Restore the other contexts.
                if (isset($safe)) {
                    switch ($populate) {
                        case 'article':
                            $thisarticle = $safe;
                            break;
                        case 'image':
                            $thisimage = $safe;
                            break;
                        case 'file':
                            $thisfile = $safe;
                            break;
                        case 'link':
                            $thislink = $safe;
                            break;
                    }
                }

                return join('', $finalout);
            }
        } else {
            return parse(EvalElse($thing, 0));
        }
    }

    return '';
}

/**
 * Internal function to parse replacement variables and globals
 *
 * URL variables are optionally run through preg_replace() to sanitize them.
 *
 * @param  string $item  The element to scan for replacements
 * @param  array  $dflts Default values to apply if any replacements are empty
 * @param  array  $pat   A set of regex search patterns
 * @param  array  $rep   A set of regex search replacements (default='', remove whatever matches)
 * @param  bool   $lax   Whether to allow spaces in pattern matches
 */
function smd_query_parse($item, $dflts = array(''), $pat = array(''), $rep = array(''), $lax = true)
{
    global $pretext, $thisarticle, $thisimage, $thisfile, $thislink, $variable;

    $item = html_entity_decode($item);

    // Sometimes pesky Unicode is not compiled in. Detect if so and fall back to ASCII.
    if (!@preg_match('/\pL/u', 'a')) {
        $modRE = ($lax) ? '/(\?)([A-Za-z0-9_\- ]+)/' : '/(\?)([A-Za-z0-9_\-]+)/';
    } else {
        $modRE = ($lax) ? '/(\?)([\p{L}\p{N}\p{Pc}\p{Pd}\p{Zs}]+)/' : '/(\?)([\p{L}\p{N}\p{Pc}\p{Pd}]+)/';
    }

    $numMods = preg_match_all($modRE, $item, $mods);

    for ($modCtr = 0; $modCtr < $numMods; $modCtr++) {
        $modChar = $mods[1][$modCtr];
        $modItem = trim($mods[2][$modCtr]);
        $lowitem = strtolower($modItem);
        $urlvar = $svrvar = '';

        if (gps($lowitem) != '') {
            $urlvar = doSlash(gps($lowitem));

            if ($urlvar && $pat) {
                $urlvar = preg_replace($pat, $rep, $urlvar);
            }
        }

        if (serverSet($modItem) != '') {
            $svrvar = doSlash(serverSet($modItem));

            if ($svrvar && $pat) {
                $svrvar = preg_replace($pat, $rep, $svrvar);
            }
        }

        if (isset($variable[$lowitem]) && $variable[$lowitem] != '') {
            $item = str_replace($modChar.$modItem, $variable[$lowitem], $item);
        } elseif ($svrvar != '') {
            $item = str_replace($modChar.$modItem, $svrvar, $item);
        } elseif (isset($thisimage[$lowitem]) && !empty($thisimage[$lowitem])) {
            $item = str_replace($modChar.$modItem, $thisimage[$lowitem], $item);
        } elseif (isset($thisfile[$lowitem]) && !empty($thisfile[$lowitem])) {
            $item = str_replace($modChar.$modItem, $thisfile[$lowitem], $item);
        } elseif (isset($thislink[$lowitem]) && !empty($thislink[$lowitem])) {
            $item = str_replace($modChar.$modItem, $thislink[$lowitem], $item);
        } elseif (array_key_exists($lowitem, $pretext) && !empty($pretext[$lowitem])) {
            $item = str_replace($modChar.$modItem, $pretext[$lowitem], $item);
        } elseif (isset($thisarticle[$lowitem]) && !empty($thisarticle[$lowitem])) {
            $item = str_replace($modChar.$modItem, $thisarticle[$lowitem], $item);
        } elseif ($urlvar != '') {
            $item = str_replace($modChar.$modItem, $urlvar, $item);
        } elseif (isset($dflts[$lowitem])) {
            $item = str_replace($modChar.$modItem, $dflts[$lowitem], $item);
        } else {
            $item = str_replace($modChar.$modItem, $modItem, $item);
        }
    }

    return $item;
}

// Convenience tag for those that prefer the security of a tag over {replacements}
function smd_query_info($atts, $thing = null)
{
    global $smd_query_data;

    extract(lAtts(array(
        'type'    => 'field', // or 'col' or 'page'
        'item'    => '',
        'wraptag' => '',
        'break'   => '',
        'class'   => '',
        'debug'   => 0,
    ), $atts));

    $qdata = is_array($smd_query_data) ? $smd_query_data : array();

    if ($debug) {
        echo '++ AVAILABLE INFO ++';
        dmp($qdata);
    }

    $items = do_list($item);
    $out = array();

    foreach ($items as $it) {
        if (isset($qdata[$type][$it])) {
            $out[] = $qdata[$type][$it];
        }
    }

    return doWrap($out, $wraptag, $break, $class);
}

/**
 * Convenience tags to check if there's a previous page defined
 *
 * Could also use smd_if plugin.
 *
 * @param  array $atts   Tag attributes
 * @param  string $thing Tag container content
 */
function smd_query_if_prev($atts, $thing)
{
    global $smd_query_pginfo;

    $res = $smd_query_pginfo && $smd_query_pginfo['{smd_prevpage}'] != '';

    return parse(EvalElse(strtr($thing, $smd_query_pginfo), $res));
}

/**
 * Convenience tags to check if there's a next page defined
 *
 * Could also use smd_if plugin.
 *
 * @param  array $atts   Tag attributes
 * @param  string $thing Tag container content
 */
function smd_query_if_next($atts, $thing)
{
    global $smd_query_pginfo;

    $res = $smd_query_pginfo && $smd_query_pginfo['{smd_nextpage}'] != '';

    return parse(EvalElse(strtr($thing, $smd_query_pginfo), $res));

}
