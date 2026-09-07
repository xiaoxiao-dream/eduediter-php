<?php
header("Content-Type: application/json;charset=utf-8");

//仅允许POST
if($_SERVER['REQUEST_METHOD'] !== 'POST'){
    echo json_encode(['ok'=>false,'msg'=>'仅POST请求']);
    exit;
}

if(!isset($_FILES['wsd_file'])){
    echo json_encode(['ok'=>false,'msg'=>'没有上传文件']);
    exit;
}

$uploadFile = $_FILES['wsd_file'];
if($uploadFile['error'] !== UPLOAD_ERR_OK){
    echo json_encode(['ok'=>false,'msg'=>'文件上传错误 code:'.$uploadFile['error']]);
    exit;
}

$tmpPath = $uploadFile['tmp_name'];

/**
 * wsd启发式解析：滑动窗口提取UTF‑16LE字符串
 * 仅预览，不保证正确性，复刻 EduConverter思路
 */
function parseWsdHeuristic(string $filePath): array
{
    $fp = fopen($filePath, 'rb');
    if(!$fp){
        return ['ok'=>false,'msg'=>'无法打开文件'];
    }
    //魔数校验 0x00 + WSTUDIO5
    $magicExpect = "\x00WSTUDIO5";
    $headBuf = fread($fp,9);
    if(strncmp($headBuf,$magicExpect,9)!==0){
        fclose($fp);
        return ['ok'=>false,'msg'=>'不是有效的WSD(WSTUDIO5)文件'];
    }

    fseek($fp,0);
    $chunkSize = 8192;
    $buffer = '';
    $outputLines = [];

    while(!feof($fp)){
        $raw = fread($fp,$chunkSize);
        $buffer .= $raw;
        $bufLen = strlen($buffer);
        $i = 0;
        while($i <= $bufLen - 2){
            $b1 = ord($buffer[$i]);
            $b2 = ord($buffer[$i+1]);
            //连续双0字节代表UTF‑16字符串结束
            if($b1 === 0 && $b2 ===0){
                $i +=2;
                continue;
            }
            $startPos = $i;
            while( ($i+1) < $bufLen && !(ord($buffer[$i])===0 && ord($buffer[$i+1])===0) ){
                $i += 2;
            }
            $slice = substr($buffer,$startPos, $i - $startPos);
            $str = iconv("UTF‑16LE","UTF‑8//IGNORE", $slice);
            $str = trim($str);
            if($str !== ''){
                $outputLines[] = $str;
            }
        }
        //保留末尾不足2字节残余
        $buffer = substr($buffer, -2);
    }
    fclose($fp);

    $finalText = implode("\n",$outputLines);
    return [
        'ok'=>true,
        'text'=>$finalText
    ];
}

$result = parseWsdHeuristic($tmpPath);
echo json_encode($result, JSON_UNESCAPED_UNICODE);
