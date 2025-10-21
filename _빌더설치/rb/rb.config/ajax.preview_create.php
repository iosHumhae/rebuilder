<?php
include_once('../../common.php');

if (!defined('_GNUBOARD_')) exit;

header('Content-Type: application/json; charset=utf-8');

$SRC_TABLE = 'rb_config';
$DST_TABLE = 'rb_preview_config';
$SRC_CO_ID = 1;

/** 유틸: 테이블 컬럼 목록 가져오기 (순서 보존) */
function get_table_columns($table_name) {
    $cols = array();
    $res = sql_query("SHOW COLUMNS FROM `{$table_name}`");
    while ($row = sql_fetch_array($res)) {
        $cols[] = $row['Field'];
    }
    return $cols;
}

/** 유틸: 6자리 랜덤 숫자 co_id 생성(중복 검사 포함) */
function generate_unique_co_id($table_name, $max_try = 50) {
    for ($i = 0; $i < $max_try; $i++) {
        $candidate = random_int(100000, 999999); // 100000~999999
        $row = sql_fetch("SELECT 1 AS ok FROM `{$table_name}` WHERE `co_id` = '{$candidate}' LIMIT 1");
        if (!$row) return (string)$candidate;
    }
    return false;
}

// 0) 소스 존재 여부 확인
$exists = sql_fetch("SELECT 1 AS ok FROM `{$SRC_TABLE}` WHERE `co_id` = '".sql_escape_string($SRC_CO_ID)."' LIMIT 1");
if (!$exists) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => "Source not found: {$SRC_TABLE}.co_id={$SRC_CO_ID}"], JSON_UNESCAPED_UNICODE);
    exit;
}

// 1) 컬럼 교집합 산출 (co_id 제외)
$src_cols = get_table_columns($SRC_TABLE);
$dst_cols = get_table_columns($DST_TABLE);

$common = array_values(array_intersect($dst_cols, $src_cols));
$common_wo_pk = array_values(array_diff($common, ['co_id'])); // co_id는 별도 세팅

// 2) pr_title/pr_desc 기본값 주입 준비 (dst에 있을 때만 추가)
$extra_cols = [];
$extra_selects = [];

if (in_array('pr_title', $dst_cols, true)) {
    $extra_cols[]     = 'pr_title';
    $extra_selects[]  = "'프리뷰 이름' AS `pr_title`";
}
if (in_array('pr_desc', $dst_cols, true)) {
    $extra_cols[]     = 'pr_desc';
    $extra_selects[]  = "'프리뷰 설명을 입력하세요.' AS `pr_desc`";
}

// 3) co_id 생성
$new_co_id = generate_unique_co_id($DST_TABLE);
if ($new_co_id === false) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Failed to generate unique 6-digit co_id.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 4) INSERT ... SELECT 구문 구성
$insert_cols = array_merge(['co_id'], $common_wo_pk, $extra_cols);
$insert_cols_sql = implode(',', array_map(function($c){ return "`{$c}`"; }, $insert_cols));

$select_parts = [];
$select_parts[] = "'{$new_co_id}' AS `co_id`";

// 공통 컬럼은 소스 값 그대로
foreach ($common_wo_pk as $c) {
    $select_parts[] = "`{$SRC_TABLE}`.`{$c}`";
}

// pr_title/pr_desc 기본값
$select_parts = array_merge($select_parts, $extra_selects);

$select_sql = implode(',', $select_parts);

$sql = "INSERT INTO `{$DST_TABLE}` ({$insert_cols_sql})
        SELECT {$select_sql}
        FROM `{$SRC_TABLE}`
        WHERE `{$SRC_TABLE}`.`co_id` = '".sql_escape_string($SRC_CO_ID)."'
        LIMIT 1";

// 5) 실행
$ok = sql_query($sql);
if (!$ok) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Insert failed.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 6) 완료
echo json_encode([
    'status' => 'ok',
    'from'   => "{$SRC_TABLE}.co_id={$SRC_CO_ID}",
    'to'     => "{$DST_TABLE}.co_id={$new_co_id}",
    'copied_columns' => $insert_cols  // 실제 매핑/주입한 컬럼 목록
], JSON_UNESCAPED_UNICODE);
