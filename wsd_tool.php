<?php
header("Content-Type: text/html;charset=utf-8");
$ajaxResult = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['wsd_file'])) {
    header("Content-Type: application/json;charset=utf-8");

    $uploadFile = $_FILES['wsd_file'];
    if ($uploadFile['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['ok' => false, 'msg' => '上传错误:' . $uploadFile['error']], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $tmpPath = $uploadFile['tmp_name'];

    /**
     * WSD启发式解析 提取UTF-16LE
     */
    function parseWsdHeuristic(string $filePath): array
    {
        $fp = fopen($filePath, 'rb');
        if (!$fp) return ['ok' => false, 'msg' => '打开文件失败'];
        // EduEditer versions may place the legacy marker after a small header,
        // so do not reject a file only because the marker is not at byte zero.
        $magicExpect = "\x00WSTUDIO5";
        $headBuf = fread($fp, 256);
        if ($headBuf === false || strlen($headBuf) === 0) {
            fclose($fp);
            return ['ok' => false, 'msg' => '文件为空或无法读取'];
        }
        $markerPos = strpos($headBuf, $magicExpect);
        $headerSkip = ($markerPos === false) ? 0 : $markerPos + strlen($magicExpect);
        fseek($fp, 0);
        $chunkSize = 8192;
        $buffer = '';
        $outputLines = [];
        $headerSkipped = false;
        $scanOffset = $headerSkip % 2;
        while (!feof($fp)) {
            $raw = fread($fp, $chunkSize);
            if ($raw === false || $raw === '') continue;
            $buffer .= $raw;
            if (!$headerSkipped && ($headerSkip === 0 || strlen($buffer) >= $headerSkip)) {
                if ($headerSkip > 0) $buffer = substr($buffer, $headerSkip);
                $headerSkipped = true;
            }
            $processable = strlen($buffer) - 512;
            if ($processable <= 0 && !feof($fp)) continue;
            $scan = $processable > 0 ? substr($buffer, 0, $processable) : $buffer;
            $buffer = $processable > 0 ? substr($buffer, $processable) : '';
            $scanLen = strlen($scan);
            for ($offset = $scanOffset; $offset < $scanOffset + 1; $offset++) {
                $run = '';
                for ($i = $offset; $i + 1 < $scanLen; $i += 2) {
                    $unit = unpack('v', substr($scan, $i, 2))[1];
                    $valid = ($unit === 9 || $unit === 10 || $unit === 13 || ($unit >= 32 && $unit !== 0xFFFE && $unit !== 0xFFFF));
                    if (!$valid) {
                        if (strlen($run) >= 4) {
                            $str = trim((string) iconv('UTF-16LE', 'UTF-8//IGNORE', $run));
                            if ($str !== '') $outputLines[] = $str;
                        }
                        $run = '';
                        continue;
                    }
                    $run .= substr($scan, $i, 2);
                }
                if (strlen($run) >= 4) {
                    $str = trim((string) iconv('UTF-16LE', 'UTF-8//IGNORE', $run));
                    if ($str !== '') $outputLines[] = $str;
                }
            }
        }
        if ($buffer !== '') {
            for ($offset = $scanOffset; $offset < $scanOffset + 1; $offset++) {
                $run = '';
                for ($i = $offset; $i + 1 < strlen($buffer); $i += 2) {
                    $unit = unpack('v', substr($buffer, $i, 2))[1];
                    if ($unit >= 32 || $unit === 9 || $unit === 10 || $unit === 13) {
                        $run .= substr($buffer, $i, 2);
                    } elseif (strlen($run) >= 4) {
                        $str = trim((string) iconv('UTF-16LE', 'UTF-8//IGNORE', $run));
                        if ($str !== '') $outputLines[] = $str;
                        $run = '';
                    }
                }
                if (strlen($run) >= 4) {
                    $str = trim((string) iconv('UTF-16LE', 'UTF-8//IGNORE', $run));
                    if ($str !== '') $outputLines[] = $str;
                }
            }
        }
        fclose($fp);
        $outputLines = array_values(array_unique($outputLines));
        $rawText = implode("\n", $outputLines);
        //文本清洗
        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $rawText);
        $clean = preg_replace('/\n{3,}/', "\n\n", $clean);
        $clean = preg_replace('/[ \t]+/u', ' ', $clean);
        return ['ok' => true, 'raw' => $rawText, 'clean' => $clean, 'headerDetected' => $headerSkip > 0];
    }

    /**
     * 试卷状态机：切分题目 题号、A-D选项、【答案】【解析】
     */
    function parsePaperStateMachine(string $text): array
    {
        $lines = explode("\n", $text);
        $questions = [];
        $current = null;
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;
            //匹配题号 1. 2.
            if (preg_match('/^(\d+)[\.、]/u', $line, $m)) {
                if ($current !== null) $questions[] = $current;
                $current = [
                    'no' => $m[1],
                    'title' => preg_replace('/^(\d+)[\.、]/u', '', $line),
                    'options' => [],
                    'answer' => '',
                    'analysis' => ''
                ];
                continue;
            }
            if ($current === null) continue;
            //A. B. C. D.
            if (preg_match('/^([A-D])[\.、]/u', $line, $optm)) {
                $optKey = $optm[1];
                $optVal = preg_replace('/^([A-D])[\.、]/u', '', $line);
                $current['options'][$optKey] = $optVal;
                continue;
            }
            //【答案】
            if (strpos($line, '【答案】') === 0) {
                $current['answer'] = trim(preg_replace('/^【答案】/u', '', $line));
                continue;
            }
            //【解析】
            if (strpos($line, '【解析】') === 0) {
                $current['analysis'] = trim(preg_replace('/^【解析】/u', '', $line));
                continue;
            }
            //其余追加到题干
            $current['title'] .= "\n" . $line;
        }
        if ($current !== null) $questions[] = $current;
        return $questions;
    }

    $res = parseWsdHeuristic($tmpPath);
    if (!$res['ok']) {
        echo json_encode($res, JSON_UNESCAPED_UNICODE);
        exit;
    }
    $qList = parsePaperStateMachine($res['clean']);
    echo json_encode([
        'ok' => true,
        'raw' => $res['raw'],
        'clean' => $res['clean'],
        'questions' => $qList
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>WSD解析工具｜单文件PHP</title>
<script src="https://unpkg.com/mathlive@0.100.0/dist/mathlive.min.js"></script>
<style>
*{box-sizing:border-box;margin:0;padding:0;font-family:system-ui,-apple-system}
body{height:100vh;display:flex;flex-direction:column;padding:14px;gap:12px}
.top{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
#file{display:none}
.btn{padding:8px 14px;border-radius:6px;border:none;background:#2563eb;color:#fff;cursor:pointer;font-size:14px}
.btn:hover{background:#1d4ed8}
.btn.success{background:#0891b2}
.progressWrap{width:280px;height:18px;background:#e5e7eb;border-radius:9px;overflow:hidden}
#progress{height:100%;width:0%;background:#22c55e;transition:.2s}
#status{font-size:14px;color:#444}
.main{display:flex;flex:1;gap:12px;overflow:hidden}
.leftCol{width:52%;display:flex;flex-direction:column;border:1px solid #ddd;border-radius:8px;overflow:hidden}
.rightCol{width:48%;display:flex;flex-direction:column;border:1px solid #ddd;border-radius:8px;overflow:hidden}
.hdr{padding:10px;background:#f3f4f6;border-bottom:1px solid #ddd;font-weight:500;font-size:14px}
.body{flex:1;padding:10px;overflow:auto}
pre{white-space:pre-wrap;font-size:13px;color:#222}
table{width:100%;border-collapse:collapse;font-size:13px;margin-bottom:10px}
th,td{border:1px solid #ccc;padding:6px}
textarea.wide{width:100%;min-height:100px;padding:8px;border:1px solid #ccc;border-radius:4px;font-size:13px}
.math-field{border:1px solid #bbb;padding:8px;border-radius:6px;margin:8px 0}
.desc{color:#b91c1c;font-size:12px;margin:4px 0}
</style>
</head>
<body>
<div class="top">
<label class="btn" for="file">选择 .wsd 文件</label>
<input id="file" type="file" accept=".wsd">
<div class="progressWrap"><div id="progress"></div></div>
<div id="status">等待上传</div>
<button class="btn success" id="exportMd">导出Obsidian Markdown</button>
</div>

<div class="main">
<div class="leftCol">
<div class="hdr">原始清洗文本｜解析题目列表（可编辑）<div class="desc">⚠️启发式解析，会乱序/碎片，务必人工校对</div></div>
<div class="body">
<pre id="rawOut"></pre>
<hr style="margin:10px 0">
<div id="qTableWrap"></div>
</div>
</div>

<div class="rightCol">
<div class="hdr">LaTeX可视化公式编辑器(MathLive)</div>
<div class="body">
<p>可视化公式：</p>
<math-field class="math-field" id="mf"></math-field>
<p style="margin-top:8px">LaTeX源码：</p>
<textarea class="wide" id="latexTxt"></textarea>
</div>
</div>
</div>

<script>
const fileInput = document.getElementById('file');
const progressBar = document.getElementById('progress');
const statusDom = document.getElementById('status');
const rawOut = document.getElementById('rawOut');
const qWrap = document.getElementById('qTableWrap');
const exportBtn = document.getElementById('exportMd');
const mf = document.getElementById('mf');
const latexTxt = document.getElementById('latexTxt');

let globalQuestions = [];

mf.addEventListener('input', ()=>latexTxt.value = mf.value);
latexTxt.addEventListener('input', ()=>mf.value = latexTxt.value);

fileInput.addEventListener('change',async ev=>{
    const f = ev.target.files[0];
    if(!f) return;
    statusDom.textContent = "上传中";
    progressBar.style.width = "0%";
    rawOut.textContent = "";
    qWrap.innerHTML = "";
    globalQuestions = [];

    const fd = new FormData();
    fd.append("wsd_file",f);
    const xhr = new XMLHttpRequest();
    xhr.open("POST","");
    xhr.upload.onprogress = e=>{
        if(e.lengthComputable){
            const p = Math.round((e.loaded/e.total)*100);
            progressBar.style.width = p+"%";
            statusDom.textContent = `上传 ${p}%`;
        }
    };
    xhr.onload = ()=>{
        progressBar.style.width = "100%";
        try{
            const ret = JSON.parse(xhr.responseText);
            if(!ret.ok){
                statusDom.textContent = "解析失败";
                rawOut.textContent = ret.msg;
                return;
            }
            statusDom.textContent = "解析完成，请校对";
            rawOut.textContent = ret.clean;
            globalQuestions = ret.questions || [];
            renderQuestionTable(globalQuestions);
        }catch(err){
            statusDom.textContent = "返回解析异常";
            rawOut.textContent = xhr.responseText;
        }
    };
    xhr.onerror = ()=>statusDom.textContent="网络错误";
    xhr.send(fd);
});

function renderQuestionTable(list){
    qWrap.innerHTML = "";
    if(!list.length){
        qWrap.innerHTML="<div>未识别到题目</div>";
        return;
    }
    const table = document.createElement('table');
    const thead = document.createElement('thead');
    thead.innerHTML = `<tr><th>题号</th><th>题干</th><th>选项</th><th>答案</th><th>解析</th></tr>`;
    table.appendChild(thead);
    const tbody = document.createElement('tbody');
    list.forEach((item,idx)=>{
        const tr = document.createElement('tr');
        tr.innerHTML = `
        <td>${item.no}</td>
        <td><textarea class="wide q-title">${escapeHtml(item.title)}</textarea></td>
        <td><textarea class="wide q-opt">${escapeHtml(JSON.stringify(item.options,null,2))}</textarea></td>
        <td><input class="q-ans" value="${escapeHtml(item.answer)}"></td>
        <td><textarea class="wide q-ana">${escapeHtml(item.analysis)}</textarea></td>
        `;
        tbody.appendChild(tr);
    });
    table.appendChild(tbody);
    qWrap.appendChild(table);
}

function escapeHtml(str){
    if(!str) return '';
    return str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

//读取表格最新编辑内容
function readTableQuestions(){
    const rows = qWrap.querySelectorAll('tbody tr');
    const out = [];
    rows.forEach(r=>{
        const title = r.querySelector('.q-title').value;
        const optRaw = r.querySelector('.q-opt').value;
        let opts={};
        try{opts=JSON.parse(optRaw)}catch(e){}
        const ans = r.querySelector('.q-ans').value;
        const ana = r.querySelector('.q-ana').value;
        out.push({title,options:opts,answer:ans,analysis:ana});
    });
    return out;
}

//导出Obsidian markdown
exportBtn.onclick = ()=>{
    const qs = readTableQuestions();
    let md = '';
    qs.forEach(q=>{
        md += `## 题目\n${q.title}\n`;
        for(const k in q.options){
            md += `${k}. ${q.options[k]}\n`;
        }
        md += `**答案**：${q.answer}\n\n`;
        md += `**解析**：${q.analysis}\n\n---\n\n`;
    });
    const blob = new Blob([md],{type:'text/markdown'});
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download="wsd-export.md";
    a.click();
};
</script>
</body>
</html>
