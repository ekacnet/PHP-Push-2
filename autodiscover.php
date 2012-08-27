<?php
/***********************************************
* File      :   autodiscover.php
* Project   :   Php-Push
* Descr     :   This is the entry point
*               through which all requests
*               are processed.
*
* Created   :   14.08.2012
*
* Copyright 2007 - 2012 Zarafa Deutschland GmbH
* Copyright 2012        Matthieu Patou
*
* This program is free software: you can redistribute it and/or modify
* it under the terms of the GNU Affero General Public License, version 3,
* as published by the Free Software Foundation with the following additional
* term according to sec. 7:
*
* According to sec. 7 of the GNU Affero General Public License, version 3,
* the terms of the AGPL are supplemented with the following terms:
*
* "Zarafa" is a registered trademark of Zarafa B.V.
* "Z-Push" is a registered trademark of Zarafa Deutschland GmbH
* The licensing of the Program under the AGPL does not imply a trademark license.
* Therefore any rights, title and interest in our trademarks remain entirely with us.
*
* However, if you propagate an unmodified version of the Program you are
* allowed to use the term "Z-Push" to indicate that you distribute the Program.
* Furthermore you may use our trademarks where it is necessary to indicate
* the intended purpose of a product or service provided you use it in accordance
* with honest practices in industrial or commercial matters.
* If you want to propagate modified versions of the Program under the name "Z-Push",
* you may only do so if you have a written permission by Zarafa Deutschland GmbH
* (to acquire a permission please contact Zarafa at trademark@zarafa.com).
*
* This program is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
* GNU Affero General Public License for more details.
*
* You should have received a copy of the GNU Affero General Public License
* along with this program.  If not, see <http://www.gnu.org/licenses/>.
*
* Consult LICENSE file for details
************************************************/

ob_start(null, 1048576);

include_once('lib/exceptions/exceptions.php');
include_once('lib/utils/utils.php');
include_once('lib/utils/compat.php');
include_once('lib/utils/timezoneutil.php');
include_once('lib/core/zpushdefs.php');
include_once('lib/core/stateobject.php');
include_once('lib/core/interprocessdata.php');
include_once('lib/core/pingtracking.php');
include_once('lib/core/topcollector.php');
include_once('lib/core/loopdetection.php');
include_once('lib/core/asdevice.php');
include_once('lib/core/statemanager.php');
include_once('lib/core/devicemanager.php');
include_once('lib/core/zpush.php');
include_once('lib/core/zlog.php');
include_once('lib/interface/ibackend.php');
include_once('lib/interface/ichanges.php');
include_once('lib/interface/iexportchanges.php');
include_once('lib/interface/iimportchanges.php');
include_once('lib/interface/isearchprovider.php');
include_once('lib/interface/istatemachine.php');
include_once('lib/core/streamer.php');
include_once('lib/core/streamimporter.php');
include_once('lib/core/synccollections.php');
include_once('lib/core/hierarchycache.php');
include_once('lib/core/changesmemorywrapper.php');
include_once('lib/core/syncparameters.php');
include_once('lib/core/bodypreference.php');
include_once('lib/core/contentparameters.php');
include_once('lib/wbxml/wbxmldefs.php');
include_once('lib/wbxml/wbxmldecoder.php');
include_once('lib/wbxml/wbxmlencoder.php');
include_once('lib/syncobjects/syncobject.php');
include_once('lib/syncobjects/syncbasebody.php');
include_once('lib/syncobjects/syncbaseattachment.php');
include_once('lib/syncobjects/syncmailflags.php');
include_once('lib/syncobjects/syncrecurrence.php');
include_once('lib/syncobjects/syncappointment.php');
include_once('lib/syncobjects/syncappointmentexception.php');
include_once('lib/syncobjects/syncattachment.php');
include_once('lib/syncobjects/syncattendee.php');
include_once('lib/syncobjects/syncmeetingrequestrecurrence.php');
include_once('lib/syncobjects/syncmeetingrequest.php');
include_once('lib/syncobjects/syncmail.php');
include_once('lib/syncobjects/syncnote.php');
include_once('lib/syncobjects/synccontact.php');
include_once('lib/syncobjects/syncfolder.php');
include_once('lib/syncobjects/syncprovisioning.php');
include_once('lib/syncobjects/synctaskrecurrence.php');
include_once('lib/syncobjects/synctask.php');
include_once('lib/syncobjects/syncoofmessage.php');
include_once('lib/syncobjects/syncoof.php');
include_once('lib/syncobjects/syncuserinformation.php');
include_once('lib/syncobjects/syncdeviceinformation.php');
include_once('lib/syncobjects/syncdevicepassword.php');
include_once('lib/syncobjects/syncitemoperationsattachment.php');
include_once('lib/syncobjects/syncsendmail.php');
include_once('lib/syncobjects/syncsendmailsource.php');
include_once('lib/default/backend.php');
include_once('lib/default/searchprovider.php');
include_once('lib/request/request.php');
include_once('lib/request/requestprocessor.php');

include_once('config.php');
include_once('version.php');


    // Attempt to set maximum execution time
    ini_set('max_execution_time', SCRIPT_TIMEOUT);
    set_time_limit(SCRIPT_TIMEOUT);

    try {
        // check config & initialize the basics
        ZPush::CheckConfig();
        Request::Initialize();
        ZLog::Initialize();

        ZLog::Write(LOGLEVEL_DEBUG,"-------- Start");
        ZLog::Write(LOGLEVEL_INFO,
                    sprintf("Version='%s' from='%s' getUser='%s'",
                                    @constant('ZPUSH_VERSION'),  Request::GetRemoteAddr(),
                                    Request::GetGETUser()));

        // Stop here if this is an OPTIONS request
        if (Request::IsMethodOPTIONS())
            throw new NoPostRequestException("Options request", NoPostRequestException::OPTIONS_REQUEST);

        ZPush::CheckAdvancedConfig();

        // Process request headers and look for AS headers
        Request::ProcessHeaders();
        //throw new FatalException("Requested the Z-Push URL without the required GET parameters". Request::GetURI());

        // Check required GET parameters
        if(Request::IsMethodPOST() && (!Request::GetGETUser() || !Request::GetURI() || (Request::GetURI() != "/autodiscover/autodiscover.xml")))
            throw new FatalException("Requested the Z-Push URL without the required GET parameters");

        // Load the backend
        $backend = ZPush::GetBackend();

        // always request the authorization header
        if (! Request::AuthenticationInfo())
            throw new AuthenticationRequiredException("Access denied. Please send authorisation information");

        // most commands require an authenticated user
        if (ZPush::CommandNeedsAuthentication(Request::GetCommandCode()))
            RequestProcessor::Authenticate();

        // Do the actual processing of the request
        if (Request::IsMethodGET())
            throw new NoPostRequestException("This is the Z-Push location and can only be accessed by Microsoft ActiveSync-capable devices", NoPostRequestException::GET_REQUEST);

        // Do the actual request
        header(ZPush::GetServerHeader());
        $server = PUSH_SERVER_NAME;
        $login = Request::GetGETUser();
        $values = parse_ad_req(Request::GetInputStream());
        $email = validate_ad_req($values);
        if ($email != "") {
            if (!(strstr($email, '@', true) == $login || $login == $email)) {
                throw new BadAutodiscoverEmail("Wrong email supplied, didn't relate to login");
            }
            printf('<?xml version="1.0"  encoding="utf-8"?>'."\r\n");
            printf('<Autodiscover xmlns="http://schemas.microsoft.com/exchange/autodiscover/responseschema/2006">'."\r\n");
            printf('<Response xmlns="http://schemas.microsoft.com/exchange/autodiscover/mobilesync/responseschema/2006">'."\r\n");
            printf('    <Culture>en:us</Culture>'."\r\n");
            printf('    <User>'."\r\n");
            //printf('        <DisplayName>Demo User</DisplayName>');
            printf('        <EMailAddress>%s</EMailAddress>'."\r\n", $email);
            printf('    </User>'."\r\n");
            printf('    <Action>'."\r\n");
            printf('        <Settings>'."\r\n");
            printf('            <Server>'."\r\n");
            printf('                <Type>MobileSync</Type>'."\r\n");
            printf('                <Url>https://%s/Microsoft-Server-ActiveSync</Url>'."\r\n", $server);
            printf('                <Name>https://%s/Microsoft-Server-ActiveSync</Name>'."\r\n", $server);
            printf('            </Server>'."\r\n");
            printf('        </Settings>'."\r\n");
            printf('    </Action>'."\r\n");
            printf('</Response>'."\r\n");
            printf('</Autodiscover>'."\r\n");
        } else
            throw new BadAutodiscoverXML("Badly formatted xml, schema or email are missing");
        $len = ob_get_length();
        $data = ob_get_contents();
        print $data;
        ob_end_clean();

        if(!headers_sent())
            header("Content-Length: $len");
            header("Content-Type: text/xml; charset=utf-8");

        print $data;

        // destruct backend after all data is on the stream
        $backend->Logoff();
    }

    catch (NoPostRequestException $nopostex) {
        if ($nopostex->getCode() == NoPostRequestException::OPTIONS_REQUEST) {
            header(ZPush::GetServerHeader());
            header(ZPush::GetSupportedProtocolVersions());
            header(ZPush::GetSupportedCommands());
            ZLog::Write(LOGLEVEL_INFO, $nopostex->getMessage());
        }
        else if ($nopostex->getCode() == NoPostRequestException::GET_REQUEST) {
            if (Request::GetUserAgent())
                ZLog::Write(LOGLEVEL_INFO, sprintf("User-agent: '%s'", Request::GetUserAgent()));
            if (!headers_sent() && $nopostex->showLegalNotice())
                ZPush::PrintZPushLegal('GET not supported', $nopostex->getMessage());
        }
    }

    catch (Exception $ex) {
        if (Request::GetUserAgent())
            ZLog::Write(LOGLEVEL_INFO, sprintf("User-agent: '%s'", Request::GetUserAgent()));
        $exclass = get_class($ex);

        if(!headers_sent()) {
            if ($ex instanceof ZPushException) {
                header('HTTP/1.1 '. $ex->getHTTPCodeString());
                foreach ($ex->getHTTPHeaders() as $h)
                    header($h);
            }
            // something really unexpected happened!
            else
                header('HTTP/1.1 500 Internal Server Error');
        }
        else
            ZLog::Write(LOGLEVEL_FATAL, "Exception: ($exclass) - headers were already sent. Message: ". $ex->getMessage());

        if ($ex instanceof AuthenticationRequiredException) {
            ZPush::PrintZPushLegal($exclass, sprintf('<pre>%s</pre>',$ex->getMessage()));

            // log the failed login attemt e.g. for fail2ban
            if (defined('LOGAUTHFAIL') && LOGAUTHFAIL != false)
                ZLog::Write(LOGLEVEL_WARN, sprintf("IP: %s failed to authenticate user '%s'",  Request::GetRemoteAddr(), Request::GetAuthUser()? Request::GetAuthUser(): Request::GetGETUser() ));
        }

        // This could be a WBXML problem.. try to get the complete request
        else if ($ex instanceof WBXMLException) {
            ZLog::Write(LOGLEVEL_FATAL, "Request could not be processed correctly due to a WBXMLException. Please report this.");
        }

        // Try to output some kind of error information. This is only possible if
        // the output had not started yet. If it has started already, we can't show the user the error, and
        // the device will give its own (useless) error message.
        else if (!($ex instanceof ZPushException) || $ex->showLegalNotice()) {
            $cmdinfo = (Request::GetCommand())? sprintf(" processing command <i>%s</i>", Request::GetCommand()): "";
            $extrace = $ex->getTrace();
            $trace = (!empty($extrace))? "\n\nTrace:\n". print_r($extrace,1):"";
            ZPush::PrintZPushLegal($exclass . $cmdinfo, sprintf('<pre>%s</pre>',$ex->getMessage() . $trace));
        }

        // Announce exception to process loop detection
        if (ZPush::GetDeviceManager(false))
            ZPush::GetDeviceManager()->AnnounceProcessException($ex);

        // Announce exception if the TopCollector if available
        ZPush::GetTopCollector()->AnnounceInformation(get_class($ex), true);
    }

    // save device data if the DeviceManager is available
    if (ZPush::GetDeviceManager(false))
        ZPush::GetDeviceManager()->Save();

    // end gracefully
    ZLog::Write(LOGLEVEL_DEBUG, '-------- End');

    function parse_ad_req($req)
    {
        $plain = "";
        while($data = fread($req, 4096)) {
              $plain .= $data;
        }
        $parser = xml_parser_create('');
        xml_parser_set_option($parser, XML_OPTION_TARGET_ENCODING, "UTF-8");
        xml_parser_set_option($parser, XML_OPTION_CASE_FOLDING, 0);
        xml_parser_set_option($parser, XML_OPTION_SKIP_WHITE, 1);
        xml_parse_into_struct($parser, trim($plain), $xml_values);
        xml_parser_free($parser);
        return $xml_values;
    }

    function validate_ad_req($xml_values) {
        $schema_ok = 0;
        $email = "";
        foreach ($xml_values as $data) {
            if (isset($data['tag']) && isset($data['value']) && 
                strtolower($data['tag']) == 'emailaddress') {
                    $email = $data['value'];
            }
            if (isset($data['tag']) && isset($data['value']) && 
                strtolower($data['tag']) == 'acceptableresponseschema' &&
                strtolower($data['value']) == 'http://schemas.microsoft.com/exchange/autodiscover/mobilesync/responseschema/2006') {
                    $schema_ok = 1;
            }
        }
        if ($schema_ok)
            return $email;
        else
            return "";
    }
?>
