/*  cleantalk.api.forVB.php */
class CleantalkAPI {
    /**
     * Universal method for form modification
     * Needed for correct JavaScript detection, for example.
     * Use it in your templates
     * @param string Type of form - 'register' or 'comment' only
     * @return string Template addon text
     */
    static function FormAddon($sType) {
    global $vbulletin;
    if($sType != 'register' && $sType != 'comment')
        return '';
    if ($vbulletin->options['cleantalk_register_onoff'] && $vbulletin->options['cleantalk_onoff']) {
	    if (!session_id()) session_start();
        $_SESSION['ct_submit_' . ($sType == 'register' ? 'register' : 'comment'). '_time'] = time();
        $ct_check_value = md5($vbulletin->options['cleantalk_key']);
        $_SESSION['ct_check_key'] = $ct_check_value;
        if (!isset($_COOKIE['ct_checkjs']))
			setcookie('ct_checkjs', $ct_check_value, time()+3600, '/');
	}
    else
        return '';
}

    /**
     * Universal method for checking comment or new user for spam
     * It makes checking itself
     * @param &array Entity to check (comment or new user)
     * @param boolean Notify admin about errors by email or not (default FALSE)
     * @return array|null Checking result or NULL when bad params
     */
    static function CheckSpam(&$arEntity, $bSendEmail = FALSE) {
      global $vbulletin;
      if(!is_array($arEntity) || !array_key_exists('type', $arEntity)){
        // log it - bad param
        $vbulletin->db->query_write("INSERT INTO " . TABLE_PREFIX . "moderatorlog (dateline, action, threadtitle, product, ipaddress) VALUES ('".TIMENOW."', '".$vbulletin->db->escape_string('CleantalkAPI::CheckSpam - bad param, not an array or no type defined')."', '', 'cleantalk', '".$vbulletin->db->escape_string(IPADDRESS)."')");
            return;
      }

        $type = $arEntity['type'];
        if($type != 'comment' && $type != 'register'){
        // log it - bad param
        $vbulletin->db->query_write("INSERT INTO " . TABLE_PREFIX . "moderatorlog (dateline, action, threadtitle, product, ipaddress) VALUES ('".TIMENOW."', '".$vbulletin->db->escape_string('CleantalkAPI::CheckSpam - bad param, wrong type defined')."', '', 'cleantalk', '".$vbulletin->db->escape_string(IPADDRESS)."')");
            return;
        }

    $ct_key = $vbulletin->options['cleantalk_key'];
        $ct_ws = self::GetWorkServer();

        if (!session_id()) session_start();

        if(!isset($_SESSION['ct_check_key']))
            $checkjs = 0;
        elseif(!isset($_COOKIE['ct_checkjs']))
            $checkjs = NULL;
        elseif($_COOKIE['ct_checkjs'] == $_SESSION['ct_check_key'])
            $checkjs = 1;
        else
            $checkjs = 0;
            
        if(isset($_SERVER['HTTP_USER_AGENT']))
            $user_agent = htmlspecialchars((string) $_SERVER['HTTP_USER_AGENT']);
        else
            $user_agent = NULL;

        if(isset($_SERVER['HTTP_REFERER']))
            $refferrer = htmlspecialchars((string) $_SERVER['HTTP_REFERER']);
        else
            $refferrer = NULL;

    $ct_language = $vbulletin->db->query_first("SELECT languagecode FROM " . TABLE_PREFIX . "language WHERE languageid='" . $vbulletin->db->escape_string($vbulletin->options['languageid']) . "'");
    $ct_language = $ct_language['languagecode'];
        $sender_info = array(
            'cms_lang' => $ct_language,
            'REFFERRER' => $refferrer,
            'post_url' => $refferrer,
            'USER_AGENT' => $user_agent
        );
        $sender_info = json_encode($sender_info);

        $ct = new Cleantalk();
        $ct->work_url = $ct_ws['work_url'];
        $ct->server_url = $ct_ws['server_url'];
        $ct->server_ttl = $ct_ws['server_ttl'];
        $ct->server_changed = $ct_ws['server_changed'];

        //$ct->data_codepage = "windows-1251"; // uncomment when cp1251

    if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])){
        $forwarded_for = (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) ? htmlentities($_SERVER['HTTP_X_FORWARDED_FOR']) : '';
    }
        $sender_ip = (!empty($forwarded_for)) ? $forwarded_for : $_SERVER['REMOTE_ADDR'];

        $ct_request = new CleantalkRequest();
        $ct_request->auth_key = $ct_key;
        $ct_request->sender_email = isset($arEntity['sender_email']) ? $arEntity['sender_email'] : '';
        $ct_request->sender_nickname = isset($arEntity['sender_nickname']) ? $arEntity['sender_nickname'] : '';
        $ct_request->sender_ip = isset($arEntity['sender_ip']) ? $arEntity['sender_ip'] : $sender_ip;
        $ct_request->agent = 'vbulletin-18';
        $ct_request->response_lang = $vbulletin->options['cleantalk_lang'];
        $ct_request->js_on = $checkjs;
        $ct_request->sender_info = $sender_info;

        $ct_submit_time = NULL;
        switch ($type) {
            case 'comment':
                if(isset($_SESSION['ct_submit_comment_time']))
                    $ct_submit_time = time() - $_SESSION['ct_submit_comment_time'];
                $timelabels_key = 'mail_error_comment';
                $ct_request->submit_time = $ct_submit_time;

                $message_title = isset($arEntity['message_title']) ? $arEntity['message_title'] : '';
                $message_body = isset($arEntity['message_body']) ? $arEntity['message_body'] : '';
                $ct_request->message = $message_title . " \n\n" . $message_body;

                $example = '';
                $a_example['title'] = isset($arEntity['example_title']) ? $arEntity['example_title'] : '';
                $a_example['body'] =  isset($arEntity['example_body']) ? $arEntity['example_body'] : '';
                $a_example['comments'] = isset($arEntity['example_comments']) ? $arEntity['example_comments'] : '';

                // Additional info.
                $post_info = '';
                $a_post_info['comment_type'] = 'comment';

                // JSON format.
                $example = json_encode($a_example);
                $post_info = json_encode($a_post_info);

                // Plain text format.
                if($example === FALSE){
                    $example = '';
                    $example .= $a_example['title'] . " \n\n";
                    $example .= $a_example['body'] . " \n\n";
                    $example .= $a_example['comments'];
                }
                if($post_info === FALSE)
                    $post_info = '';

                // Example text + last N comments in json or plain text format.
                $ct_request->example = $example;
                $ct_request->post_info = $post_info;
                $ct_result = $ct->isAllowMessage($ct_request);
                break;
            case 'register':
                if(isset($_SESSION['ct_submit_register_time']))
                    $ct_submit_time = time() - $_SESSION['ct_submit_register_time'];

                $timelabels_key = 'mail_error_reg';
                $ct_request->submit_time = $ct_submit_time;
                $ct_request->tz = isset($arEntity['user_timezone']) ? $arEntity['user_timezone'] : NULL;
                $ct_result = $ct->isAllowUser($ct_request);
                break;
        }
        
        $ret_val = array();
        $ret_val['ct_request_id'] = $ct_result->id;

        if($ct->server_change)
            self::SetWorkServer(
                $ct->work_url, $ct->server_url, $ct->server_ttl, time()
            );

        // First check errstr flag.
        if(!empty($ct_result->errstr)
            || (!empty($ct_result->inactive) && $ct_result->inactive == 1)
        ){
            // Cleantalk error so we go default way (no action at all).
            $ret_val['errno'] = 1;
            // Just inform admin.
            $err_title = 'CleanTalk module error. Please contact your forum administrator.';

            if(!empty($ct_result->errstr)){
            if (preg_match('//u', $ct_result->errstr)){
                        $err_str = preg_replace('/^[^\*]*?\*\*\*|\*\*\*[^\*]*?$/iu', '', $ct_result->errstr);
            }else{
                        $err_str = preg_replace('/^[^\*]*?\*\*\*|\*\*\*[^\*]*?$/i', '', $ct_result->errstr);
            }
            }else{
            if (preg_match('//u', $ct_result->comment)){
                $err_str = preg_replace('/^[^\*]*?\*\*\*|\*\*\*[^\*]*?$/iu', '', $ct_result->comment);
            }else{
                $err_str = preg_replace('/^[^\*]*?\*\*\*|\*\*\*[^\*]*?$/i', '', $ct_result->comment);
            }
        }

            $ret_val['errstr'] = $err_str;
            
        // log it - server error
        $vbulletin->db->query_write("INSERT INTO " . TABLE_PREFIX . "moderatorlog (dateline, action, threadtitle, product, ipaddress) VALUES ('".TIMENOW."', '".$vbulletin->db->escape_string($err_str)."', '', 'cleantalk', '".$vbulletin->db->escape_string(IPADDRESS)."')");

            if($bSendEmail){
                $send_flag = FALSE;
                $insert_flag = FALSE;
                $time = $vbulletin->db->query_first('SELECT ct_value FROM ' . TABLE_PREFIX. 'cleantalk_timelabels WHERE ct_key=\''. $timelabels_key .'\'');
                if(!$time || empty($time)){
                    $send_flag = TRUE;
                    $insert_flag = TRUE;
                }elseif(time()-900 > $time['ct_value']) {       // 15 minutes
                    $send_flag = TRUE;
                    $insert_flag = FALSE;
                }
                if($send_flag){
                    if($insert_flag){
            $vbulletin->db->query_write("
                INSERT INTO " . TABLE_PREFIX . "cleantalk_timelabels
                (ct_key, ct_value)
                VALUES
                ('$timelabels_key', " . time() . ")
            ");
                }else{
            $vbulletin->db->query_write("
                UPDATE " . TABLE_PREFIX . "cleantalk_timelabels
                SET ct_key='$timelabels_key', ct_value=" . time()
            );
                }

            $ct_admin_users = $vbulletin->db->query_read("SELECT * FROM " . TABLE_PREFIX . "user WHERE usergroupid=6");
            while ($ct_admin_user = $vbulletin->db->fetch_array($ct_admin_users)) {
            vbmail($ct_admin_user['email'],  $err_title, $err_str. "\nHost ".$_SERVER['SERVER_NAME']."\nTime ".date('Y-m-d H:i:s'));
            }
                }
            }
            $ret_val['errno'] = 0;
            $ret_val['allow'] = 0;
            $ret_val['ct_result_comment'] = $err_title;
            $ret_val['stop_queue'] = 1;
            return $ret_val;
        }

        $ret_val['errno'] = 0;
        if ($ct_result->allow == 1) {
            // Not spammer.
            $ret_val['allow'] = 1;
            ///$GLOBALS['ct_request_id'] = $ct_result->id;
        }else{
            $ret_val['allow'] = 0;
            $ret_val['ct_result_comment'] = $ct_result->comment;
            // Spammer.
            // Check stop_queue flag.
            if($type == 'comment' && $ct_result->stop_queue == 0) {
                // Spammer and stop_queue == 0 - to manual approvement.
                $ret_val['stop_queue'] = 0;
                ///$GLOBALS['ct_request_id'] = $ct_result->id;
                ///$GLOBALS['ct_result_comment'] = $ct_result->comment;
            }else{
                // New user or Spammer and stop_queue == 1 - display message and exit.
                $ret_val['stop_queue'] = 1;
            }
        }
        return $ret_val;
    }


    /**
     * CleanTalk inner function - gets working server.
     */
    private static function GetWorkServer() {
        global $vbulletin;
        $result = $vbulletin->db->query_first('SELECT work_url,server_url,server_ttl,server_changed FROM ' . TABLE_PREFIX . 'cleantalk_server LIMIT 1');
        if($result && !empty($result))
            return array(
                'work_url' => $result['work_url'],
                'server_url' => $result['server_url'],
                'server_ttl' => $result['server_ttl'],
                'server_changed' => $result['server_changed'],
            );
        else
            return array(
                'work_url' => 'http://moderate.cleantalk.ru',
                'server_url' => 'http://moderate.cleantalk.ru',
                'server_ttl' => 0,
                'server_changed' => 0,
            );
    }

    /**
     * CleanTalk inner function - sets working server.
     */
    private static function SetWorkServer($work_url = 'http://moderate.cleantalk.ru', $server_url = 'http://moderate.cleantalk.ru', $server_ttl = 0, $server_changed = 0) {
        global $vbulletin;
        $result = $vbulletin->db->query_first('SELECT count(*) AS count FROM ' . TABLE_PREFIX . 'cleantalk_server');
        $count = $result['count'];
        if($count == 0){
        $vbulletin->db->query_write("
        INSERT INTO " . TABLE_PREFIX . "cleantalk_server
            (work_url, server_url, server_ttl, server_changed)
        VALUES
            ('$work_url', '$server_url', $server_ttl, $server_changed)
        ");
        }else{
        $vbulletin->db->query_write("
        UPDATE " . TABLE_PREFIX . "cleantalk_server
        SET work_url='$work_url', server_url='$server_url', server_ttl=$server_ttl, server_changed=$server_changed
        ");
        }
    }

}// class CleantalkAPI
// end of cleantalk.api.forVB.php