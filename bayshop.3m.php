#!/opt/homebrew/bin/php
<?php

// <bitbar.title>BayShop Checker</bitbar.title>
// <bitbar.version>v0.2</bitbar.version>
// <bitbar.author>Sergey Fokin</bitbar.author>
// <bitbar.author.github>tyraelqp</bitbar.author.github>
// <bitbar.desc>Checks your parcels on bayshop.com.</bitbar.desc>
// <bitbar.image>https://bayshop.com/img/svg/logo-mob.svg</bitbar.image>
// <bitbar.dependencies>php,imagick</bitbar.dependencies>
// <swiftbar.hideAbout>true</swiftbar.hideAbout>
// <swiftbar.hideRunInTerminal>true</swiftbar.hideRunInTerminal>
// <swiftbar.hideSwiftBar>true</swiftbar.hideSwiftBar>
// <swiftbar.hideDisablePlugin>true</swiftbar.hideDisablePlugin>

const SESSION_FILE = '/.bayshop_session_id';
const CACHE_FILE = '/.bayshop_cache';
const BASE_URL = 'https://bayshop.com';
const LOGO_URL = BASE_URL . '/img/svg/logo-mob.svg';
const REFRESH_BUTTON = 'Обновить | href=swiftbar://refreshplugin?name=bayshop';

define('IS_DARK_THEME', 'Dark' === getenv('OS_APPEARANCE'));
const NO_ITEMS_COLOR = IS_DARK_THEME
    ? '#eeeeee'
    : '#333333';

if (!file_exists(__DIR__ . SESSION_FILE)) {
    file_put_contents(__DIR__ . SESSION_FILE, '');
}

if (!file_exists(__DIR__ . CACHE_FILE)) {
    file_put_contents(__DIR__ . CACHE_FILE, '{}');
}

define('SESSION_ID', trim(file_get_contents(__DIR__ . SESSION_FILE)));

if (empty(SESSION_ID)) {
    die(sprintf(
        "|image=%s\n---\nНет ID сессии в файле %s | color=%s",
        getColoredLogo('#ff0000'),
        SESSION_FILE,
        NO_ITEMS_COLOR,
    ));
}

enum Platform: string
{
    case MAC_OS = 'Darwin';
    case LINUX = 'Linux';

    public function showToastNotification(string $title, string $message): void
    {
        $command = match ($this) {
            self::MAC_OS => sprintf(
                'osascript -e \'display notification "%s" with title "%s" sound name "Glass.aiff"\'',
                $message,
                $title,
            ),
            self::LINUX => sprintf(
                'notify-send "%s" "%s"',
                $title,
                $message,
            ),
        };

        exec($command);
    }
}

enum Status: string
{
    case UNKNOWN = 'Статус неизвестен';
    case ON_THE_WAY = 'В пути';
    case PROCESSING = 'В обработке';
    case PACKED = 'Упакованные';
    case SHIPPED = 'Отправленные';
    case CUSTOMS = 'Растаможить товар';
    case READY = 'Готово к выдаче';
    case WAITING_FOR_COURIER = 'Ожидает курьера';
    case UNRECOGNIZED = 'Неизвестный статус';

    public function getColor(): ?string
    {
        return match ($this) {
            self::ON_THE_WAY,
            self::UNKNOWN => '#cccccc',
            self::PROCESSING => '#af3a94',
            self::PACKED => '#2090d1',
            self::SHIPPED => '#ff8c00',
            self::READY => '#3eb950',
            self::UNRECOGNIZED,
            self::CUSTOMS => '#ff0000',
            self::WAITING_FOR_COURIER => '#ffc80a',
            default => null,
        };
    }

    public function getText(): ?string
    {
        return match ($this) {
            self::UNKNOWN => 'Неизвестен',
            self::PACKED => 'Упаковано',
            self::SHIPPED => 'Отправлено',
            self::CUSTOMS => 'Растаможка',
            default => $this->value,
        };
    }

    public function getWeight(): int
    {
        return match ($this) {
            self::UNRECOGNIZED => 100,
            self::READY => 13,
            self::WAITING_FOR_COURIER => 12,
            self::CUSTOMS => 11,
            self::SHIPPED => 10,
            self::PACKED => 9,
            self::PROCESSING => 5,
            self::ON_THE_WAY => 4,
            self::UNKNOWN => 1,
        };
    }
}

function getColoredLogo(string $color): string
{
    $svg = file_get_contents(LOGO_URL);
    $svg = preg_replace('/fill="#\w+"/', "fill=\"$color\"", $svg);
    $im = new Imagick();
    $im->readImageBlob($svg);
    $im->setImageFormat('png64');
    $im->transparentPaintImage("white", 0, 0, false);
    $im->resizeImage(18, 18, Imagick::FILTER_LANCZOS, 1);

    return base64_encode($im);
}

function loadPage(string $path): string
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => "https://bayshop.com/RU/$path",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Cookie: Bay=' . SESSION_ID,
            'X-Requested-With: XMLHttpRequest',
        ],
    ]);

    $response = curl_exec($ch);
    curl_close($ch);

    return $response;
}

function loadItemsFromPage(string $path): array
{
    $results = [];

    $response = loadPage($path);

    $document = new DOMDocument();
    @$document->loadHTML('<?xml encoding="utf-8" ?>' . $response);
    $finder = new DOMXPath($document);
    $ids = $finder->query("//*[contains(@class, 'td-id')]");
    $titles = $finder->query("//*[contains(@class, 'td-text')]");
    $statuses = $finder->query("//*[contains(@class, 'td-label')]");

    foreach ($ids as $i => $id) {
        /** @var DOMElement $id */
        /** @var DOMElement $title */
        $title = $titles->item($i);
        $titleValue = trim($title->textContent);
        $statusValue = trim($statuses->item($i)->textContent);
        preg_match('/\s(R\d+)/', $id->textContent, $idMatches);
        $idValue = !empty($idMatches)
            ? $idMatches[1]
            : md5($titleValue);

        $results[] = [
            'id' => $idValue,
            'title' => $titleValue,
            'rawStatus' => $statusValue,
            'status' => Status::tryFrom($statusValue) ?? Status::UNRECOGNIZED,
            'href' => $title->firstElementChild->getAttribute('href'),
        ];
    }

    return $results;
}

try {
    $platform = Platform::from(PHP_OS);
} catch (ValueError) {
    die(sprintf(
        "|image=%s\n---\nНеподдерживаемоя ОС %s | color=%s",
        getColoredLogo('#ff0000'),
        SESSION_FILE,
        NO_ITEMS_COLOR,
    ));
}

$cache = json_decode(
    json: file_get_contents(__DIR__ . CACHE_FILE),
    associative: true,
    flags: JSON_THROW_ON_ERROR,
);

$results = [];
$results[] = loadItemsFromPage('mf-packages/');
$results[] = loadItemsFromPage('package/?status=processing');
$results[] = loadItemsFromPage('package/?status=packed');
$results[] = loadItemsFromPage('package/?status=ready-to-pickup');
$results[] = loadItemsFromPage('package/?status=sent');
$results[] = loadItemsFromPage('package/?status=customs-held');
$results[] = loadItemsFromPage('package/?status=local-depo');
$results = array_merge(...$results);

usort(
    $results,
    static fn(array $a, array $b) => -($a['status']->getWeight() <=> $b['status']->getWeight()),
);

if (empty($results)) {
    die(sprintf(
        "|image=%s\n---\nНет посылок | color=%s\n---\n%s",
        getColoredLogo(NO_ITEMS_COLOR),
        NO_ITEMS_COLOR,
        REFRESH_BUTTON,
    ));
}

echo sprintf(
    "|image=%s\n---\n",
    getColoredLogo(current($results)['status']->getColor()),
);

$hasChanges = false;

foreach ($results as $item) {
    printf(
        "%s: %s | %s color=%s\n",
        $item['title'],
        Status::UNRECOGNIZED === $item['status']
            ? $item['rawStatus']
            : $item['status']->getText(),
        !empty($item['href'])
            ? sprintf('href=%s%s', BASE_URL, $item['href'])
            : '',
        $item['status']->getColor(),
    );

    if (($cache[$item['id']] ?? null) !== $item['status']->name) {
        $hasChanges = true;

        $platform->showToastNotification(
            $item['title'],
            "Статус посылки сменился на {$item['status']->getText()}",
        );
    }

    $cache[$item['id']] = $item['status']->name;
}

if ($hasChanges) {
    file_put_contents(
        __DIR__ . CACHE_FILE,
        json_encode($cache, JSON_THROW_ON_ERROR),
    );
}

echo "---\n";
echo REFRESH_BUTTON;
