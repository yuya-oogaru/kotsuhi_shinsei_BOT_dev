/**************************************************************
*           ** LINE TEST BOT Project(2018/08/03)**
*
*
*                   テストプログラム！！
*                   本番で使用しないこと
*             処理を全部メインメソッドに書いてるけど
*                   本番では直します・・・・
*************************************************************/

<?php

// Composerでインストールしたライブラリを一括読み込み
require_once __DIR__ . '/vendor/autoload.php';


/************************マクロ定義*************************/
define('TABLE_NAME', 'routes');

/***********************************************************/


/************************************************************
＊ここからリプライトークン取得までは変えないで
*************************************************************/

// 送られて来たJSONデータを取得
$json_string = file_get_contents('php://input');
error_log(file_get_contents('php://input'));
$json = json_decode($json_string);

/****署名認証****/

// アクセストークンを使いCurlHTTPClientをインスタンス化
$httpClient = new \LINE\LINEBot\HTTPClient\CurlHTTPClient(getenv('CHANNEL_ACCESS_TOKEN'));
// CurlHTTPClientとシークレットを使いLINEBotをインスタンス化
$bot = new \LINE\LINEBot($httpClient, ['channelSecret' => getenv('CHANNEL_SECRET')]);
// LINE Messaging APIがリクエストに付与した署名を取得
$signature = $_SERVER['HTTP_' . \LINE\LINEBot\Constant\HTTPHeader::LINE_SIGNATURE];
// 署名が正当かチェック。正当であればリクエストをパースし配列へ
$events = $bot->parseEventRequest(file_get_contents('php://input'), $signature);

/****************/

/*受信メッセージ抽出*/
$getMessage = $json->events[0]->message->text; 

/*リプライトークン（返信証明）取得*/
$replyToken = $json->events[0]->replyToken;

/*ジョルダンのメッセージかどうか判断*/
$messageType = mb_strpos($getMessage, 'ジョルダン乗換案内', 4, "UTF-8");

/*ユーザー情報*/
/**************************
$response  :ユーザーＩＤ
$profile   :ユーザープロフィール(表示名,ユーザーID,画像のURL,ステータスメッセージ)
***************************/
$response = $json->events[0]->source->userId;
$profile = $bot->getProfile($response)->getJSONDecodedBody();

$preSendMessage = 'default text';/*テキスト初期化*/
$stickerType = 1;

/******ジョルダンのメッセージでない場合はここを実行して終了******/
if($messageType == false){
	/*メッセージに対して返信を変える*/
	switch($getMessage){
		case 'テスト':
			$preSendMessage = 'テスト完了！';
			$stickerType = 114;
			break;
		case '大軽':
			$preSendMessage = '開発者の名前';
			$stickerType = 119;
			break;
		case 'うるさい':
			return;
		case '合計':
			$totalPrice = calcTotalPrice($response);
			$preSendMessage = ''.$profile['displayName'].'さんが登録した経路の運賃合計は['.(number_format($totalPrice)).'円]です。';
			$stickerType = 2;
			
			if($totalPrice == NULL){
				$preSendMessage = ''.$profile['displayName'].'さんが登録した経路はありません。';
				$stickerType = 111;
			}

			break;
		case '削除':
			deleteRouteData($response);
			$preSendMessage = ''.$profile['displayName'].'さんが登録した経路は削除されました。';
			$stickerType = 108;
			break;
		default :
			$preSendMessage = "無効なメッセージです。\n
現在、当ＢＯＴがサポートしている経路情報は、ジョルダンフォーマットのみとなっています.";
			$stickerType = 113;
			break;
	}

	foreach ($events as $event) {
		replyMultiMessage($bot, $replyToken, 
			new \LINE\LINEBot\MessageBuilder\TextMessageBuilder($preSendMessage),
			new \LINE\LINEBot\MessageBuilder\StickerMessageBuilder(1, $stickerType)
		);
	}
	return;
}
/**************ここまで**************/

/****************************************************************
             ジョルダンフォーマット（android受信データ）

例):桜ノ宮～京橋　間

桜ノ宮〜京橋  8/ 3(金) 14:09 - 14:11\n
2分　乗換0回　120円\n
--------------------\n
切符利用時の運賃です。\n
[ 8/ 3]\n
14:09発　桜ノ宮\n
　大阪環状線京橋方面\n
14:11着　京橋\n
\n
--------------------\n
詳しい結果はコチラ\n
ジョルダン乗換案内\n
(検索URL)\n

****************************************************************/

/*交通費データの抽出場所特定(ジョルダンフォーマット（android）のみ対応)*/
/*******************************
$routeNamePos      :経路の記述箇所（末尾）
$dateEndPos        :乗車日の記述箇所（末尾）
$transitTimePos    :乗換回数の記述箇所（先頭）
$transitTimeEndPos :乗換回数の記述箇所（末尾）
$totalPricePos     :運賃合計の記述箇所（先頭）
$totalPriceEndPos  :運賃合計の記述箇所（末尾）
********************************/
$routeNamePos = mb_strpos($getMessage, ' ',1 , "UTF-8");
$dateEndPos = mb_strpos($getMessage, ')',$routeNamePos , "UTF-8");
$transitTimePos = mb_strpos($getMessage, '乗換', 1, "UTF-8");
$transitTimeEndPos = mb_strpos($getMessage, '回', 1, "UTF-8");
$totalPricePos = mb_strpos($getMessage, '　', $transitTimePos, "UTF-8");
$totalPricePos += 1;/*ぱでぃんぐ*/
$totalPriceEndPos = mb_strpos($getMessage, '円', $totalPricePos, "UTF-8");

$preSendMessage = 'default text';/*テキスト初期化*/

/*****データ抽出*****/


/*******交通費データ*******/
/**************************
$route      :経路
$travelData :乗車日
$transit    :乗換回数
$price      :運賃（合計）
***************************/
$routes = mb_substr($getMessage, 0, $routeNamePos, "UTF-8");
$travelDate = mb_substr($getMessage, ($routeNamePos + 1), (($dateEndPos - $routeNamePos) + 1), "UTF-8");
$transit = mb_substr($getMessage, ($transitTimePos + 2), ($transitTimeEndPos - ($transitTimePos + 2)), "UTF-8");
$price = mb_substr($getMessage, $totalPricePos, ($totalPriceEndPos - $totalPricePos), "UTF-8");

/******************DB参照・登録*********************/

/*ユーザー情報がＤＢにない場合*/

registerUser($profile['displayName'], date('Y/m/d'), $routes, $price, $response);


/***************返信******************/
/*以下、インデントがおかしいのは、表示文字列内にインデントのＴＡＢが挿入されてしまうため*/

/*ジョルダン乗換案内がlinebotに張り付けられたとき*/
if($messageType != false){
	foreach ($events as $event) {
		replyMultiMessage($bot, $replyToken, 
			new \LINE\LINEBot\MessageBuilder\TextMessageBuilder(
'交通費データは以下の内容で登録可能です。
				
'.'登録者名 : ['.$profile['displayName'].']
'.'登録日　 : ['.date('Y/m/d').']
			
'.'経路 : ['.$routes.']
'.'乗車日 : ['.$travelDate.']
'.'乗換回数 : ['.$transit.'回]
			
'.'運賃合計 : ['.$price.'円]'
			),
			new \LINE\LINEBot\MessageBuilder\StickerMessageBuilder(3, 229)
		);
	}
}else{

}


/***************個人合計算出**************/
function calcTotalPrice($usersid){

	$dbh = dbConnection::getConnection();
	$sth = $dbh -> prepare("SELECT SUM(price) from routes WHERE userid = :searchId");
	$sth->bindValue(':searchId', $usersid, PDO::PARAM_STR);   
	$sth->execute();
	$result = $sth->fetch(PDO::FETCH_NUM);

	return $result[0];
}

/************削除（個人データのみ）*******/
function deleteRouteData($usersid){

	$dbh = dbConnection::getConnection();
	$sth = $dbh -> prepare("delete from routes WHERE userid = :searchId");
	$sth->bindValue(':searchId', $usersid, PDO::PARAM_STR);   
	$sth->execute();
}
/*******ＤＢにユーザーを追加する関数*******/

function registerUser($name, $add_date, $route, $price, $userid){
	$dbh = dbConnection::getConnection();
	$sql = 'insert into '. TABLE_NAME .' (name, date, route, price, userid, comfirmed) values (:name, :date, :route, :price, :userid, :comfirmed)';
	$sth = $dbh->prepare($sql);
	
	$sth->bindValue(':name', $name, PDO::PARAM_STR);            /*登録者名（ラインアカウント名）*/
	$sth->bindValue(':date', $add_date, PDO::PARAM_STR);        /*登録日*/
	$sth->bindValue(':route', $route, PDO::PARAM_STR);          /*登録経路*/
	$sth->bindValue(':price', (intval(str_replace(',', '', $price))), PDO::PARAM_INT);/*運賃合計*/
	$sth->bindValue(':userid', $userid, PDO::PARAM_INT);        /*userID*/
	$sth->bindValue(':comfirmed', 0, PDO::PARAM_INT);           /*確定の有無(0＝未確定、1＝確定済)*/
	
	$sth->execute();
}

/******メッセージランチャ******/
function replyMultiMessage($bot, $replyToken, ...$msgs) {
	// MultiMessageBuilderをインスタンス化
	$builder = new \LINE\LINEBot\MessageBuilder\MultiMessageBuilder();
	// ビルダーにメッセージを全て追加
	foreach($msgs as $value) {
		$builder->add($value);
	}
	$response = $bot->replyMessage($replyToken, $builder);
	
	if (!$response->isSucceeded()) {
		error_log('Failed!'. $response->getHTTPStatus . ' ' . $response->getRawBody());
	}
}

/*データベース接続クラス*/

class dbConnection{
	// インスタンス
	protected static $db;
	// コンストラクタ
	private function __construct() {
		try {
			// 環境変数からデータベースへの接続情報を取得し
			$url = parse_url(getenv('DATABASE_URL'));
			// データソース
			$dsn = sprintf('pgsql:host=%s;dbname=%s', $url['host'], substr($url['path'], 1));
			// 接続を確立
			self::$db = new PDO($dsn, $url['user'], $url['pass']);
			// エラー時例外を投げるように設定
			self::$db->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
		}
		catch (PDOException $e) {
			error_log('Connection Error: ' . $e->getMessage());
		}
	}

	// シングルトン。存在しない場合のみインスタンス化
	public static function getConnection() {
		if (!self::$db) {
			new dbConnection();
		}
		return self::$db;
	}
}
?>
