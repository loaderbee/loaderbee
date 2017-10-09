<?php
define('CHUNK_SIZE', 1024*1024); // Size (in bytes) of tiles chunk
$url = null;
$app_id = "132846743969294";
$app_secret = "f2314db3e1263d71ec8676eb93741b23";
$server_url = "http://".$_SERVER['HTTP_HOST'].explode('?', $_SERVER['REQUEST_URI'], 2)[0];
$oath_url = "https://www.facebook.com/v2.9/dialog/oauth?client_id=".$app_id."&redirect_uri=";

$URL_KEY_START_UPLOAD = "start_upload";
$URL_KEY_FB_ID = "fb_id";

$REQUEST_URL = "http://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";

if($_POST){
    $url = $_POST["url"];
    header("Location: ".$oath_url.urlencode("$server_url?url=".urlencode($url)));
    die();
}

if($_GET && key_exists("code", $_GET) && !key_exists($URL_KEY_FB_ID, $_GET)){
    $access_code = $_GET["code"];
    $url = $_GET["url"];
    $redirect_url = urlencode("$server_url?url=".urlencode($url));

    $response = get_access_token($access_code, $redirect_url);
    $params = json_decode($response);
    $fb_user = get_user_details($params->access_token);
    header("Location: ".$oath_url.urlencode("$server_url?".$URL_KEY_FB_ID."=".$fb_user->id
            ."&url=".urlencode($url)));
    die();
}

if($_GET && key_exists("code", $_GET) && key_exists($URL_KEY_FB_ID, $_GET)) {
    $access_code = $_GET["code"];
    $url = $_GET["url"];
    $fb_id = $_GET[$URL_KEY_FB_ID];
    $redirect_url = urlencode("$server_url?".$URL_KEY_FB_ID."=".$fb_id ."&url=".urlencode($url));
    $response = get_access_token($access_code, $redirect_url);
    $params = json_decode($response);
    $video_access_token = $params->access_token;

    $file_size =  retrieve_remote_file_size($url);

    $result = start_upload_process($video_access_token, $file_size, $fb_id);
    $start_message = json_decode($result);

    try{
        if(key_exists("start_offset", $start_message)){
            echo "downloading...<br/>";
            $start_offset = $start_message->start_offset;
            $end_offset = $start_message->end_offset;
            $upload_url = "https://graph-video.facebook.com/v2.3/$fb_id/videos";
            $upload_session_id =$start_message->upload_session_id;
            echo "Start Offset: $start_offset -  End Offset: $end_offset<br/>";

            while ($start_offset != $end_offset){
                $data = download_partial($url, $start_offset, $end_offset);
                $part_res = facebook_partial_upload($data,$fb_id, $video_access_token, $start_offset, $upload_session_id);
                $start_offset = $part_res->start_offset;
                $end_offset = $part_res->end_offset;
                if($part_res->error){
                    throw new Exception(get_fb_error_message($part_res->error));
                }
                echo "Start Offset: $start_offset -  End Offset: $end_offset<br/>";
            }
            $post_video_res = post_video($video_access_token, $fb_id, $upload_session_id);
            if($post_video_res->error){
                throw new Exception(get_fb_error_message($post_video_res->error));
            }
            print_r($post_video_res);
            echo "Finished.<br/>";
        }
        else{
            if($start_message->error){
                throw new Exception(get_fb_error_message($start_message->error));
            }
            else{
                throw new Exception("An error occurred");
            }
        }
    }
    catch (Exception $exception){
        echo $exception->getMessage();
    }

}

function get_fb_error_message($error){
    return json_encode($error);
}
function post_video($access_token, $fb_id, $upload_session_id){
    $upload_url = "https://graph-video.facebook.com/v2.3/$fb_id/videos";
    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL,$upload_url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS,
        http_build_query(array('access_token' => $access_token,
            'upload_phase' => 'finish',
            "upload_session_id" => $upload_session_id
        )));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $server_output = curl_exec ($ch);

    curl_close ($ch);
    return json_decode($server_output);
}

function download_partial($url, $start_offset, $end_offset){
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RANGE, "$start_offset-$end_offset");
    curl_setopt($ch, CURLOPT_BINARYTRANSFER, 1);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    $result = curl_exec($ch);
    curl_close($ch);
    return $result;
}

function facebook_partial_upload($data,$fb_id, $access_token, $start_offset, $upload_session_id){
    $upload_url = "https://graph-video.facebook.com/v2.3/$fb_id/videos";
    $ch = curl_init();
    $file = tempnam(sys_get_temp_dir(), uniqid());
    file_put_contents($file, $data);

    $text_fields = array('access_token' => $access_token,
        'upload_phase' => 'transfer',
        'start_offset' => $start_offset,
        "upload_session_id" => $upload_session_id,
        'video_file_chunk' => "@$file"
    );

    curl_setopt($ch, CURLOPT_URL,$upload_url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS,$text_fields);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);


    $server_output = curl_exec ($ch);

    curl_close ($ch);
    unlink($file);
    return json_decode($server_output);
}

function start_upload_process($access_token, $file_size, $fb_id){
    $upload_url = "https://graph-video.facebook.com/v2.3/$fb_id/videos";
    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL,$upload_url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS,
          http_build_query(array('access_token' => $access_token,
              'upload_phase' => 'start',
              'file_size' => $file_size
              )));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $server_output = curl_exec ($ch);

    curl_close ($ch);
    return $server_output;

}
function get_access_token($code, $redirect_url){
    $app_id = "132846743969294";
    $app_secret = "f2314db3e1263d71ec8676eb93741b23";

    $token_url = "https://graph.facebook.com/oauth/access_token?"
        . "client_id=" . $app_id . "&redirect_uri=" . $redirect_url
        . "&client_secret=" . $app_secret . "&code=" . $code;
    $ch = curl_init();
    $headers = array(
        'Accept: application/json',
        'Content-Type: application/json',

    );
    curl_setopt($ch, CURLOPT_URL, $token_url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_HEADER, 0);

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $authToken = curl_exec($ch);

    return $authToken;
}

function get_user_details($token){
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $graph_url = "https://graph.facebook.com/me?access_token=" .$token. "&fields=id,name,email";
    curl_setopt($ch, CURLOPT_URL,$graph_url);
    $result=curl_exec($ch);
    curl_close($ch);

    return json_decode($result);
}

function retrieve_remote_file_size($url){
    $ch = curl_init($url);

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
    curl_setopt($ch, CURLOPT_HEADER, TRUE);
    curl_setopt($ch, CURLOPT_NOBODY, TRUE);

    $data = curl_exec($ch);
    $size = curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);

    curl_close($ch);
    return $size;
}
?>

<html>
<head>
    <title>Loader Bee</title>
</head>
<body>
<h2>Loader Bee</h2>
<div>Remotely upload videos to Facebook!</div>
<h4>Upload a file</h4>
<form method="post">
    <table>
        <tbody>
        <tr>
            <th><label for="url">File url:</label></th>
            <td><input name="url" value="http://techslides.com/demos/sample-videos/small.mp4"></td>
        </tr>
        <tr>
            <th></th>
            <td><input type="submit" value="Upload"></td>
        </tr>
        </tbody>
    </table>
</form>

</body>
</html>
