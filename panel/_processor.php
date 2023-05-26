<?php

function JoinSegment($ChID, $ChName, $Keys, $aHeader, $aData, $vHeader, $vData, $DownloadIndex)
{
    global $WorkPath, $BinPath;
    global $FFMpegCMD;
    global $DeleteEncryptedAfterDecrypt;
    global $DeleteDecryptedAfterMerge;
    global $PlaylistLimit;
    global $db;
    global $CheckKey;

    $MyFFMpegCMD = $FFMpegCMD;

    $Index = str_pad($DownloadIndex, 8, "0", STR_PAD_LEFT);
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        $Mp4Decrypt = $BinPath . '\\mp4decrypt.exe ';
        $FFMpegBin = $BinPath . '\\ffmpeg.exe ';
        $Redirect = " > nul";
    } else {
        $Mp4Decrypt = $BinPath . '/mp4decrypt ';
        $FFMpegBin = 'ffmpeg ';
        $Redirect = " 2>&1 & ";
    }

    $audio_ext = ".m4a";
    $video_ext = ".mp4";
    $OutExt = ".mp4";
    $OutExt2 = ".ts";

    $Merged_FileName = $WorkPath . "/" . $ChName . "/stream/$Index" . $OutExt2;

    $map = "";
    /** let mp4decrypt bruteforce the key */
    $keyString = "";
    foreach ($Keys as $key) {
        $kid = $key["KID"];
        $decKey = $key['Key'];
        $keyString .= "--key $kid:$decKey ";
    }
    DoLog("Decrypting segment .... please wait .....");
    for ($k = 0; $k < count($aData); $k++) {
        $AudioEncFileName[] = $WorkPath . "/" . $ChName . "/seg/" . $Index . "-" . $k . "-enc" . $audio_ext;
        $AudioDecFileName[] = $WorkPath . "/" . $ChName . "/seg/" . $Index . "-" . $k . "-dec" . $audio_ext;
        $aSeg = null;
        $aSeg = $aHeader . $aData[$k];
        file_put_contents($AudioEncFileName[$k], $aSeg);
        $dec = $Mp4Decrypt . $keyString . $AudioEncFileName[$k] . " " . $AudioDecFileName[$k] . " --show-progress " . $Redirect;
        exec($dec);
        $map .= " -map " . ($k + 1) . ":a ";
    }

    $VideoEncFileName = $WorkPath . "/" . $ChName . "/seg/" . $Index . "-enc" . $video_ext;
    $VideoDecFileName = $WorkPath . "/" . $ChName . "/seg/" . $Index . "-dec" . $video_ext;
    $vSeg = $vHeader . $vData;
    file_put_contents($VideoEncFileName, $vSeg);
    $dec = $Mp4Decrypt . $keyString . $VideoEncFileName . " " . $VideoDecFileName . " --show-progress " . $Redirect;
    exec($dec);

    $MyFFMpegCMD = str_replace("-i", "", $MyFFMpegCMD);
    $MyFFMpegCMD = str_replace("[VIDEO]", " -i " . $VideoDecFileName, $MyFFMpegCMD);
    for ($k = 0; $k < count($aData); $k++) {
        $strAudioIn .= " -copyts -i " . $AudioDecFileName[$k] . " ";
    }
    $MyFFMpegCMD = str_replace("[AUDIO]", $strAudioIn, $MyFFMpegCMD);
    $MyFFMpegCMD = str_replace("[OUTPUT]", $Merged_FileName, $MyFFMpegCMD);
    $cmd = $FFMpegBin . " -copyts " . $MyFFMpegCMD . $Redirect;

    //$cmd=$FFMpegBin." -hide_banner -start_at_zero -correct_ts_overflow 0 -avoid_negative_ts disabled -max_interleave_delta 0 -i $VideoDecFileName $strAudioIn -map 0:v $map -c:v copy -c:a copy $Merged_FileName";
    $cmd = $FFMpegBin . " -hide_banner -probesize 10M -analyzeduration 10M -fflags +igndts -copyts -i $VideoDecFileName $strAudioIn -map 0:v $map -c:v copy -c:a aac -bsf:a aac_adtstoasc $Merged_FileName ";
    echo $cmd;
    $Res = null;
    exec($cmd, $Res);

    if ($CheckKey) {
        $cmd = "ffmpeg -v error -i $Merged_FileName -f null - > $WorkPath/$ChName/log/checkkey.txt 2>&1";
        exec($cmd);
        $Err = file_get_contents("$WorkPath/$ChName/log/checkkey.txt");
        if (strpos($Err, "error while decoding") === false) {
            //ok
        } else {
            UpdateChanStatus2($ChID, "KeyError");
            die();
        }
    }

    $cmd = "ffprobe -v quiet -print_format json -show_streams -show_format $Merged_FileName > a.json";
    exec($cmd);
    $v = json_decode(file_get_contents("a.json"), true);
    unlink("a.json");

    $info["vcodec"] = $v["streams"][0]["codec_name"];
    $info["width"] = $v["streams"][0]["width"];
    $info["height"] = $v["streams"][0]["height"];
    $info["ratio"] = $v["streams"][0]["display_aspect_ratio"];
    $info["framerate"] = $v["streams"][0]["avg_frame_rate"];
    $info["acodec"] = $v["streams"][1]["codec_name"];
    $info["channels"] = $v["streams"][1]["channel_layout"];
    $info["samplerate"] = $v["streams"][1]["sample_rate"];
    $info["bitrate"] = $v["format"]["bit_rate"];
    $data = json_encode($info);
    if ($info["vcodec"]) {
        $sql = "update channels set info='$data' where ID=$ChID";
        $db->exec($sql);
    }

    if ($DeleteEncryptedAfterDecrypt) {
        array_map('unlink', array_filter((array) $AudioEncFileName));
        unlink($VideoEncFileName);
    }
    if ($DeleteDecryptedAfterMerge) {
        array_map('unlink', array_filter((array) $AudioDecFileName));
        unlink($VideoDecFileName);
    }
}