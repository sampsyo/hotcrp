<?php
// paperactions.php -- HotCRP helpers for common paper actions
// HotCRP is Copyright (c) 2008-2014 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class PaperStatus {

    private $uploaded_documents = array();
    private $errf = array();
    private $errmsg = array();
    private $nerror = 0;

    static function row_to_json($prow, $contact, $options) {
        global $Conf;
        if (!$prow || !$contact->canViewPaper($prow))
            return null;
        $forceShow = @$options["forceShow"];

        $pj = (object) array();
        $pj->id = $prow->paperId;
        $pj->title = $prow->title;

        if ($prow->timeWithdrawn > 0) {
            $pj->status = "withdrawn";
            $pj->withdrawn = true;
            $pj->withdrawn_at = (int) $prow->timeWithdrawn;
            if (@$prow->withdrawReason)
                $pj->withdrawn_reason = $prow->withdrawReason;
        } else if ($prow->timeSubmitted > 0) {
            $pj->status = "submitted";
            $pj->submitted = true;
        } else
            $pj->status = "inprogress";
        if ($prow->timeSubmitted > 0)
            $pj->submitted_at = (int) $prow->timeSubmitted;
        else if ($prow->timeSubmitted == -100 && $prow->timeWithdrawn > 0)
            $pj->submitted_at = 1000000000;
        if ($prow->timestamp > 0)
            $pj->updated_at = (int) $prow->timestamp;

        $can_view_authors = $contact->canViewAuthors($prow, $forceShow);
        if ($can_view_authors) {
            $contacts = array();
            foreach ($prow->contacts(true) as $id => $conf)
                $contacts[$conf->email] = true;

            $pj->authors = array();
            foreach ($prow->authorTable as $au) {
                $aux = (object) array();
                if ($au[2])
                    $aux->email = $au[2];
                if ($au[0])
                    $aux->first = $au[0];
                if ($au[1])
                    $aux->last = $au[1];
                if ($au[3])
                    $aux->affiliation = $au[3];
                if (@$aux->email && @$contacts[$aux->email])
                    $aux->contact = true;
                $pj->authors[] = $aux;
            }
            $pj->contacts = (object) $contacts;
        }

        if ((isset($pj->paperBlind) ? !$prow->paperBlind : $prow->blind)
            && $Conf->subBlindOptional())
            $pj->nonblind = true;

        $pj->abstract = $prow->abstract;

        $usenames = @$options["usenames"];
        $topics = array();
        foreach (array_intersect_key($Conf->topic_map(), array_flip($prow->topics())) as $tid => $tname)
            $topics[$usenames ? $tname : $tid] = true;
        if (count($topics))
            $pj->topics = (object) $topics;

        if ($prow->paperStorageId > 1 && $contact->canDownloadPaper($prow))
            $pj->submission = (object) array("docid" => (int) $prow->paperStorageId);

        if (count($prow->options())) {
            $options = array();
            foreach ($prow->options() as $oa) {
                $o = $oa->option;
                if (!$contact->canViewPaperOption($prow, $o))
                    continue;
                $okey = $usenames ? $o->abbr : $o->id;
                if ($o->type == "checkbox" && $oa->value)
                    $options[$okey] = true;
                else if ($o->has_selector()
                         && @($otext = $o->selector[$oa->value]))
                    $options[$okey] = $otext;
                else if ($o->type == "numeric" && $oa->value != ""
                         && $oa->value != "0")
                    $options[$okey] = $oa->value;
                else if ($o->type == "text" && $oa->data != "")
                    $options[$okey] = $oa->data;
                else if ($o->type == "attachments") {
                    $attachments = array();
                    foreach ($oa->values as $docid)
                        if ($docid)
                            $attachments[] = (object) array("docid" => $docid);
                    if (count($attachments))
                        $options[$okey] = $attachments;
                } else if ($o->is_document() && $oa->value)
                    $options[$okey] = (object) array("docid" => $oa->value);
            }
            if (count($options))
                $pj->options = (object) $options;
        }

        if ($can_view_authors) {
            $pcconflicts = array();
            foreach ($prow->pc_conflicts(true) as $id => $conf) {
                if (@($ctname = Conflict::$type_names[$conf->conflictType]))
                    $pcconflicts[$conf->email] = $ctname;
            }
            if (count($pcconflicts))
                $pj->pc_conflicts = (object) $pcconflicts;
        }

        if ($prow->collaborators && $can_view_authors)
            $pj->collaborators = $prow->collaborators;

        return $pj;
    }

    private function set_error($field, $html) {
        if ($field)
            $this->errf[$field] = true;
        $this->errmsg[] = $html;
        ++$this->nerrors;
    }

    private function set_warning($field, $html) {
        if ($field)
            $this->errf[$field] = true;
        $this->errmsg[] = $html;
    }

    private function upload_document($docj, $paperid, $doctype) {
        if (!@$docj->docid && @$docj->content) {
            $doc = DocumentHelper::upload(new HotCRPDocument($doctype), $docj, (object) array("paperId" => $paperid));
            if (@$doc->paperStorageId > 1) {
                $docj->docid = $doc->paperStorageId;
                foreach (array("size", "sha1", "mimetype", "timestamp") as $k)
                    $docj->$k = $doc->$k;
                $this->uploaded_documents[] = $doc->paperStorageId;
            } else {
                $opt = PaperOption::find($doctype);
                $docj->docid = 1;
                $this->set_error($opt->abbr, htmlspecialchars($opt->name) . ": " . $doc->error_html);
            }
        }
    }

    function clean($pj, $allow_file_upload) {
        global $Now;
        foreach (array("topics", "options", "contacts") as $k)
            if (!is_object(@$pj->$k))
                $pj->$k = (object) array();

        // Title, abstract
        foreach (array("title", "abstract", "collaborators") as $k) {
            if (@$pj->$k && !is_string(@$pj->$k))
                $this->set_error($k, "Format error.");
            if (!is_string(@$pj->$k))
                $pj->$k = "";
        }
        $pj->title = simplify_whitespace($pj->title);
        if ($pj->title == "")
            $this->set_error("title", "Each paper must have a title.");
        $pj->abstract = trim($pj->abstract);
        if ($pj->abstract == "")
            $this->set_error("abstract", "Each paper must have an abstract.");

        // Authors, collaborators
        $revau = array();
        $curau = is_array(@$pj->authors) ? $pj->authors : array();
        foreach ($curau as $au) {
            if (!isset($au->first) && !isset($au->last) && isset($au->name))
                list($au->first, $au->last) = Text::split_name($au->name);
            if (@($au->first || $au->last || $au->email || $au->affiliation))
                $revau[] = $au;
            else
                $this->set_error("author", "Author missing required information.");
        }
        $pj->authors = $revau;
        if (!count($revau))
            $this->set_error("author", "Each paper must have at least one author.");
        $pj->collaborators = trim($pj->collaborators);

        // Status
        if (@$pj->withdrawn && !isset($pj->withdrawn_at))
            $pj->withdrawn_at = $Now;
        if (@$pj->submitted && !isset($pj->submitted_at))
            $pj->submitted_at = $Now;
        if (@$pj->final && !isset($pj->final_at))
            $pj->final_at = $Now;
        foreach (array("withdrawn_at", "submitted_at", "final_at") as $k)
            if (isset($pj->$k)) {
                if (is_numeric($pj->$k))
                    $pj->$k = (int) $pj->$k;
                else if (is_string($pj->$k))
                    $pj->$k = strtotime($pj->$k, $Now);
                else
                    $pj->$k = false;
                if ($pj->$k === false || $pj->$k < 0)
                    $pj->$k = $Now;
            }

        // Topics
        if (@$pj->topics) {
            $topic_map = $Conf->topic_map();
            if (is_array($pj->topics)) {
                $new_topics = (object) array();
                foreach ($pj->topics as $v)
                    if ($v && (is_int($v) || is_string($v)))
                        $new_topics[$v] = true;
                    else if ($v)
                        $this->set_error("topics", "Topic format error.");
                $pj->topics = $new_topics;
            }

            // canonicalize topics to use IDs, not names
            $new_topics = (object) array();
            foreach ($pj->topics as $k => $v)
                if ($v && @$topic_map[$k])
                    $new_topics[$k] = true;
                else if ($v && ($x = array_search($k, $topic_map)) !== false)
                    $new_topics[$x] = true;
                else if ($v)
                    $this->set_warning("topics", "Unknown topic “" . htmlspecialchars($k) . "” ignored.");
        }

        // Options
        if (@$pj->options) {
            $option_list = PaperOption::option_list();

            // canonicalize option values to use IDs, not abbreviations
            $new_options = (object) array();
            $known = array();
            foreach ($option_list as $id => $o) {
                $oabbr = $o->abbr;
                $known[$id] = $known[$oabbr] = true;
                if (($oa = @$pj->options->$id)
                    || ($oabbr && ($oa = @$pj->options->$oabbr)))
                    $new_options->$id = $oa;
            }
            // complain about weird options
            foreach ($pj->options as $id => $oa)
                if (!@$known[$id])
                    $this->set_warning("opt$id", "Unknown option “" . htmlspecialchars($id) . "” ignored.");
            $pj->options = $new_options;

            // check values
            foreach ($pj->options as $id => $oa) {
                $o = $option_list[$id];
                if ($o->type == "checkbox") {
                    if (!is_bool($oa))
                        $this->set_error("opt$id", htmlspecialchars($o->name) . ": Option should be “true” or “false”.");
                } else if ($o->has_selector()) {
                    if (is_int($oa) && isset($o->selectors[$oa]))
                        /* OK */;
                    else if (is_string($oa)
                             && ($ov = array_search($oa, $o->selectors)))
                        $pj->options->$id = $ov;
                    else
                        $this->set_error("opt$id", htmlspecialchars($o->name) . ": Option doesn’t match any of the selectors.");
                } else if ($o->type == "numeric") {
                    if (!is_int($oa))
                        $this->set_error("opt$id", htmlspecialchars($o->name) . ": Option should be an integer.");
                } else if ($o->type == "text") {
                    if (!is_string($oa))
                        $this->set_error("opt$id", htmlspecialchars($o->name) . ": Option should be a text string.");
                } else if ($o->type == "attachments" || $o->is_document()) {
                    if ($o->is_document() && !is_object($oa))
                        $oa = null;
                    $oa = $oa && !is_array($oa) ? array($oa) : $oa;
                    if (!is_array($oa))
                        $this->set_error("opt$id", htmlspecialchars($o->name) . ": Option format error.");
                    else
                        foreach ($oa as $ov)
                            if (!is_object($ov)
                                && !($allow_file_upload && is_string($ov)))
                                $this->set_error("opt$id", htmlspecialchars($o->name) . ": Option format error.");
                } else
                    unset($pj->options->$id);
            }
        }

        // PC conflicts
        $new_conflicts = (object) array();
        if (@$pj->pc_conflicts)
            foreach ($pj->pc_conflicts as $email => $ct) {
                if ($ct === "none" || $ct === "" || $ct === false || $ct === 0)
                    continue;
                if ($ct === "conflict")
                    $ct = true;
                if (!($pccid = pcByEmail($email)))
                    $this->set_warning("pc_conflicts", "Unknown PC conflict email “" . htmlspecialchars($email) . "” ignored.");
                else {
                    if (is_int($ct) && isset(Conflict::$type_names[$ct]))
                        $ctn = $ct;
                    else if (($ctn = array_search($ct, Conflict::$type_names)) === false) {
                        $this->set_warning("pc_conflicts", "Unknown PC conflict type “" . htmlspecialchars($ct) . "” changed to “other”.");
                        $ctn = array_search("other", Conflict::$type_names);
                    }
                    $new_conflicts->$email = $ctn;
                }
            }
        $pj->pc_conflicts = $new_conflicts;

        // Contacts
        $new_contacts = (object) array();
        foreach ($pj->authors as $au)
            if (@$au->contact) {
                if (@$au->email && validateEmail($au->email))
                    $new_contacts[$au->email] = $au;
                else if (@$au->email)
                    $this->set_error("contact", "Contact " . Text::name_html($au) . " has invalid email address “" . htmlspecialchars($au->email) . "”.");
                else
                    $this->set_error("contact", "No email address from contact " . Text::name_html($au) . ".");
            }
        if (@$pj->contacts)
            foreach ($pj->contacts as $email => $v)
                if ($v) {
                    if ($v === true && !@$new_contacts->$email)
                        $v = (object) array("email" => $email);
                    if (is_object($v)) {
                        if (!@$v->email)
                            $v->email = $email;
                        if (!@$new_contacts->$email)
                            $new_contacts->$email = $v;
                    } else
                        $this->set_error("contact", "Contact format error.");
                }
        $pj->contacts = $new_contacts;
    }

    static function topics_sql($pj) {
        $x = array();
        if ($pj && @$pj->topics)
            foreach ($pj->topics as $id => $v)
                $x[] = "($id,$pj->id)";
        sort($x);
        return join(",", $x);
    }

    static function options_sql($pj) {
        $x = array();
        if ($pj && @$pj->options) {
            $option_list = PaperOption::option_list();
            foreach ($pj->options as $id => $oa) {
                $o = $option_list[$id];
                if ($o->type == "text")
                    $x[] = "($pj->id,$o->id,1,'" . sqlq($oa) . "')";
                else if ($o->is_document())
                    $x[] = "($pj->id,$o->id,$oa->docid,null)";
                else if ($o->type == "attachments") {
                    $oa = is_array($oa) ? $oa : array($oa);
                    foreach ($oa as $ord => $ov)
                        $x[] = "($pj->id,$o->id,$ov->docid,'" . ($ord + 1) . "')";
                } else
                    $x[] = "($pj->id,$o->id,$oa,null)";
            }
        }
        sort($x);
        return join(",", $x);
    }

    static function conflicts_array($pj, $old_pj) {
        $x = array();
        if ($pj && @$pj->pc_conflicts)
            foreach ($pj->pc_conflicts as $email => $type)
                $x[strtolower($email)] = $type;
        if ($pj && @$pj->authors)
            foreach ($pj->authors as $au)
                if (@$au->email)
                    $x[strtolower($au->email)] = CONFLICT_AUTHOR;
        if ($pj && @$pj->contacts)
            foreach ($pj->contacts as $email => $crap) {
                $email = strtolower($email);
                if (!@$x[$email] || $x[$email] < CONTACT_AUTHOR)
                    $x[$email] = CONFLICT_CONTACTAUTHOR;
            }
        if ($old_pj && @$old_pj->pc_conflicts)
            foreach ($old_pj->pc_conflicts as $email => $type)
                if ($type == CONFLICT_CHAIRMARK) {
                    $email = strtolower($email);
                    if (@($x[$email] < CONFLICT_CHAIRMARK))
                        $x[$email] = CONFLICT_CHAIRMARK;
                }
        ksort($x);
        return $x;
    }

    function save($pj, $old_pj) {
        global $Now;

        $pj->id = $old_pj ? $old_pj->id : -1;
        $this->clean($pj);
        if ($old_pj)
            $this->clean($old_pj);

        // store all documents
        if (@$pj->submission)
            $this->upload_document($pj->submission, $pj->id, DTYPE_SUBMISSION);
        if (@$pj->final)
            $this->upload_document($pj->final, $pj->id, DTYPE_FINAL);
        if (@$pj->options) {
            $option_list = PaperOption::option_list();
            foreach ($pj->options as $id => $oa) {
                $o = $option_list[$id];
                if ($o->type == "attachments" || $o->is_document()) {
                    $oa = is_array($oa) ? $oa : array($oa);
                    foreach ($oa as $x)
                        $this->upload_document($x, $pj->id, $id);
                }
            }
        }
        if (@$pj->contacts)
            foreach ($pj->contacts as $email => $c)
                if (!@$old_pj->contacts->$email) {
                    if (!validateEmail($c->email))
                        $this->set_error("contact", "Contact “" . Text::name_html($c) . "” has invalid email address “" . htmlspecialchars($c->email) . "”.");
                    else if (!Contact::find_by_email($c->email, $c, true))
                        $this->set_error("contact", "Could not create an account for contact " . Text::user_html($c) . ".");
                }

        // catch errors
        if ($this->nerrors)
            return false;

        // update Paper table
        $q = array();
        if (!$old_pj || $old_pj->title != @$pj->title)
            $q[] = "title='" . sqlq($pj->title) . "'";
        if (!$old_pj || $old_pj->abstract != @$pj->abstract)
            $q[] = "abstract='" . sqlq($pj->abstract) . "'";
        if (!$old_pj || $old_pj->collaborators != @$pj->collaborators)
            $q[] = "collaborators='" . sqlq($pj->collaborators) . "'";

        $autext = $old_autext = "";
        foreach ($pj->authors as $au)
            $autext .= defval($au, "first", "") . "\t" . defval($au, "last", "") . "\t"
                . defval($au, "email", "") . "\t" . defval($au, "affiliation", "") . "\n";
        foreach (($old_pj ? $old_pj->authors : array()) as $au)
            $old_autext .= defval($au, "first", "") . "\t" . defval($au, "last", "") . "\t"
                . defval($au, "email", "") . "\t" . defval($au, "affiliation", "") . "\n";
        if ($autext != $old_autext || !$old_pj)
            $q[] = "authorInformation='" . sqlq($autext) . "'";

        if ($Conf->subBlindOptional()
            && (!$old_pj || !$old_pj->nonblind != !@$pj->nonblind))
            $q[] = "blind=" . (@$pj->nonblind ? 1 : 0);

        if (!@$pj->submission && $old_pj && $old_pj->submission)
            $q[] = "paperStorageId=1";
        else if (@$pj->submission
                 && (!$old_pj || !@$old_pj->submission
                     || $old_pj->submission->docid != $pj->submission->docid))
            $q[] = "paperStorageId=" . $pj->submission->docid;
        if (!@$pj->final && $old_pj && $old_pj->final)
            $q[] = "finalPaperStorageId=0";
        else if (@$pj->final
                 && (!$old_pj || !@$old_pj->final
                     || $old_pj->final->docid != $pj->final->docid))
            $q[] = "finalPaperStorageId=" . $pj->final->docid;

        if (@$pj->withdrawn) {
            if (!$old_pj || !@$old_pj->withdrawn) {
                $q[] = "timeWithdrawn=" . $pj->withdrawn_at;
                $q[] = "timeSubmitted=" . ($pj->submitted_at ? -100 : 0);
            } else if ((@$old_pj->submitted_at > 0) != (@$pj->submitted_at > 0))
                $q[] = "timeSubmitted=-100";
        } else if (@$pj->submitted) {
            if (!$old_pj || !@$old_pj->submitted)
                $q[] = "timeSubmitted=" . $pj->submitted_at;
            if ($old_pj && @$old_pj->withdrawn)
                $q[] = "timeWithdrawn=0";
        } else if ($old_pj && (@$old_pj->withdrawn || @$old_pj->submitted)) {
            $q[] = "timeSubmitted=0";
            $q[] = "timeWithdrawn=0";
        }

        if (count($q)) {
            if (!$Conf->subBlindOptional())
                $q[] = "blind=" . ($Conf->subBlindNever() ? 0 : 1);

            $joindoc = $old_joindoc = null;
            if (@$pj->final) {
                $joindoc = $pj->final;
                $old_joindoc = $old_pj ? @$old_pj->final : null;
            } else if (@$pj->submission) {
                $joindoc = $pj->submission;
                $old_joindoc = $old_pj ? @$old_pj->submission : null;
            }
            if ($joindoc
                && (!$old_joindoc || $old_joindoc->docid != $joindoc->docid)) {
                $q[] = "size=" . $joindoc->size;
                $q[] = "mimetype='" . sqlq($joindoc->mimetype) . "'";
                $q[] = "sha1='" . sqlq($joindoc->sha1) . "'";
                $q[] = "timestamp=" . $joindoc->timestamp;
            } else if (!$joindoc)
                $q[] = "size=0,mimetype='',sha1='',timestamp=0";

            if ($pj->id)
                $result = $Conf->qe("update Paper set " . join(",", $q) . " where paperId=$pj->id");
            else {
                $result = $Conf->qe("insert into Paper set " . join(",", $q));
                if (!$result
                    || !($pj->id = $Conf->lastInsertId()))
                    return $this->set_error(false, "Could not create paper.");
                if (count($this->uploaded_documents))
                    $Conf->qe("update PaperStorage set paperId=$pj->id where paperStorageId in (" . join(",", $this->uploaded_documents) . ")");
            }
        }

        // update PaperTopics
        if (@$pj->topics || ($old_pj && @$old_pj->topics)) {
            $topics = self::topics_sql($pj);
            $old_topics = self::topics_sql($old_pj);
            if ($topics != $old_topics) {
                $result = $Conf->qe("delete from PaperTopic where paperId=$pj->id");
                if ($topics)
                    $result = $Conf->qe("insert into PaperTopic (topicId,paperId) values $topics");
            }
        }

        // update PaperOption
        if (@$pj->options || ($old_pj && @$old_pj->options)) {
            $options = self::options_sql($pj);
            $old_options = self::options_sql($old_pj);
            if ($options != $old_options) {
                $result = $Conf->qe("delete from PaperOption where paperId=$pj->id");
                if ($options)
                    $result = $Conf->qe("insert into PaperOption (paperId,optionId,value,data) values $options");
            }
        }

        // update PaperConflict
        $conflict = self::conflicts_array($pj, $old_pj);
        $old_conflict = self::conflicts_array($old_pj, null);
        if (join(",", array_keys($conflict)) != join(",", array_keys($old_conflict))
            || join(",", array_values($conflict)) != join(",", array_values($old_conflict))) {
            $q = array();
            foreach ($conflict as $email => $type)
                $q[] = "'" . sqlq($email) . "'";
            $ins = array();
            if (count($q)) {
                $result = $Conf->qe("select contactId, email from ContactInfo where email in (" . join(",", $q) . ")");
                while (($row = edb_row($result)))
                    $ins[] = "($pj->id,$row[0]," . $conflict[strtolower($row[1])] . ")";
            }
            $result = $Conf->qe("delete from PaperConflict where paperId=$pj->id");
            if (count($ins))
                $result = $Conf->qe("insert into PaperConflict (paperId,contactId,conflictType) values " . join(",", $ins));
        }
    }

}
