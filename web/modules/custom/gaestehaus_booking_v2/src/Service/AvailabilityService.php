<?php

namespace Drupal\gaestehaus_booking\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Verfügbarkeits-Service.
 * Alle Parameter aus gaestehaus_booking.settings.
 */
class AvailabilityService {

  protected $entityTypeManager;
  protected $config;

  const STATUS_NOT_OPEN   = 'not_open';
  const STATUS_PAST       = 'past';
  const STATUS_BLOCKED    = 'blocked';
  const STATUS_WEEKDAY    = 'weekday';
  const STATUS_WEEKEND    = 'weekend';
  const STATUS_WE_BLOCKED = 'we_blocked';

  public function __construct(EntityTypeManagerInterface $em, ConfigFactoryInterface $cf) {
    $this->entityTypeManager = $em;
    $this->config = $cf->get('gaestehaus_booking.settings');
  }

  public function getOpeningDate(): \DateTime {
    return new \DateTime(($this->config->get('opening_date') ?? '2099-01-01') . 'T00:00:00');
  }

  public function isBeforeOpening(\DateTime $date): bool {
    return $date < $this->getOpeningDate();
  }

  public function isWeekday(\DateTime $date): bool {
    return in_array((int)$date->format('w'), $this->config->get('weekday_days') ?? [1,2,3,4,5]);
  }

  public function isWeekendDay(\DateTime $date): bool {
    return in_array((int)$date->format('w'), $this->config->get('weekend_days') ?? [0,6]);
  }

  protected array $bookingCache = [];

  public function getMonthAvailability(int $year, int $month): array {
    $result  = [];
    $days    = cal_days_in_month(CAL_GREGORIAN, $month, $year);
    $today   = new \DateTime('today');
    $opening = $this->getOpeningDate();

    for ($day = 1; $day <= $days; $day++) {
      $date = new \DateTime(sprintf('%04d-%02d-%02d', $year, $month, $day));
      $ds   = $date->format('Y-m-d');
      $mode = $this->isWeekendDay($date) ? 'weekend' : 'weekday';

      if ($date < $opening) {
        $result[$ds] = ['status' => self::STATUS_NOT_OPEN, 'mode' => $mode, 'bookable' => FALSE, 'reason' => 'before_opening'];
        continue;
      }
      if ($date < $today) {
        $result[$ds] = ['status' => self::STATUS_PAST, 'mode' => $mode, 'bookable' => FALSE];
        continue;
      }
      if ($mode === 'weekend') {
        $dow   = (int) $date->format('w');
        $sat   = $dow === 6 ? $date : (clone $date)->modify('-1 day');
        $avail = $this->isWeekendAvailable($sat);
        $result[$ds] = ['status' => $avail ? self::STATUS_WEEKEND : self::STATUS_WE_BLOCKED, 'mode' => 'weekend', 'bookable' => $avail && $dow === 6, 'price' => 0];
        continue;
      }
      $rooms = $this->getFreeRooms($date);
      $desks = $this->getFreeTagesplaetze($date);
      $result[$ds] = ['status' => self::STATUS_WEEKDAY, 'mode' => 'weekday', 'bookable' => !empty($rooms) || $desks > 0, 'free_rooms' => $rooms, 'free_desks' => $desks, 'tagesplatz_price' => 0];
    }
    return $result;
  }

  /** Zeiten + Reinigungspuffer je Typ (gaestehaus_booking.settings:timing). */
  protected function timing(string $type): array {
    $defaults = [
      'zimmer_desk' => ['checkin' => '15:00', 'checkout' => '10:00', 'buffer_hours' => 4],
      'woche'       => ['checkin' => '15:00', 'checkout' => '10:00', 'buffer_hours' => 4],
      'tagesplatz'  => ['checkin' => '07:00', 'checkout' => '18:00', 'buffer_hours' => 0],
      'weekend'     => ['checkin' => '15:00', 'checkout' => '14:00', 'buffer_hours' => 16],
      'haus'        => ['checkin' => '15:00', 'checkout' => '10:00', 'buffer_hours' => 8],
    ];
    $cfg = $this->config->get('timing') ?? [];
    return ($cfg[$type] ?? []) + ($defaults[$type] ?? $defaults['zimmer_desk']);
  }

  /** Belegungsfenster [von, bis) inkl. Reinigungspuffer. */
  public function interval(string $type, string $start, string $end): array {
    $t = $this->timing($type);
    if ($type === 'tagesplatz') {
      // Tagesplatz: date_end ist exklusiv (Folgetag).
      $end = (new \DateTime($end))->modify('-1 day')->format('Y-m-d');
      if ($end < $start) $end = $start;
    }
    $from  = new \DateTime($start . ' ' . $t['checkin']);
    $until = (new \DateTime($end . ' ' . $t['checkout']))->modify('+' . (int) $t['buffer_hours'] . ' hours');
    return [$from, $until];
  }

  protected function nodeInterval($node): ?array {
    $type = $node->get('field_gh_booking_type')->value;
    if ($type === 'weekend') {
      $sat = $node->get('field_gh_weekend_saturday')->value;
      if (!$sat) return NULL;
      return $this->interval('weekend', $sat, (new \DateTime($sat))->modify('+1 day')->format('Y-m-d'));
    }
    $s = $node->get('field_gh_date_start')->value;
    $e = $node->get('field_gh_date_end')->value;
    return ($s && $e) ? $this->interval($type, $s, $e) : NULL;
  }

  protected function loadBookingsAround(\DateTime $from, \DateTime $to): array {
    $lo  = (clone $from)->modify('-3 days')->format('Y-m-d');
    $hi  = (clone $to)->modify('+3 days')->format('Y-m-d');
    $key = "$lo|$hi";
    if (isset($this->bookingCache[$key])) return $this->bookingCache[$key];

    $storage = $this->entityTypeManager->getStorage('node');

    $q1 = $storage->getQuery()->accessCheck(FALSE)
      ->condition('type', 'gh_buchung')
      ->condition('field_gh_booking_type', ['zimmer_desk', 'woche', 'tagesplatz', 'haus'], 'IN')
      ->condition('field_gh_date_start', $hi, '<')
      ->condition('field_gh_date_end', $lo, '>');
    $q1->condition($q1->orConditionGroup()
      ->condition('field_gh_status', 'cancelled', '<>')
      ->notExists('field_gh_status'));

    $q2 = $storage->getQuery()->accessCheck(FALSE)
      ->condition('type', 'gh_buchung')
      ->condition('field_gh_booking_type', 'weekend')
      ->condition('field_gh_weekend_saturday', $lo, '>=')
      ->condition('field_gh_weekend_saturday', $hi, '<=');
    $q2->condition($q2->orConditionGroup()
      ->condition('field_gh_status', 'cancelled', '<>')
      ->notExists('field_gh_status'));

    $nids = array_merge(array_values($q1->execute()), array_values($q2->execute()));
    return $this->bookingCache[$key] = $nids ? $storage->loadMultiple($nids) : [];
  }

  /** Buchungen der gegebenen Typen, die sich mit $req überschneiden. */
  protected function overlapping(array $req, array $types, ?int $room = NULL): array {
    $hits = [];
    foreach ($this->loadBookingsAround($req[0], $req[1]) as $node) {
      $type = $node->get('field_gh_booking_type')->value;
      if (!in_array($type, $types, TRUE)) continue;
      if ($room !== NULL && in_array($type, ['zimmer_desk', 'woche'], TRUE)
          && (int) $node->get('field_gh_room_number')->value !== $room) continue;
      $iv = $this->nodeInterval($node);
      if ($iv && $iv[0] < $req[1] && $req[0] < $iv[1]) $hits[] = $node;
    }
    return $hits;
  }

  public function isWeekendAvailable(\DateTime $saturday): bool {
    $sat = $saturday->format('Y-m-d');
    $req = $this->interval('weekend', $sat, (clone $saturday)->modify('+1 day')->format('Y-m-d'));
    return !$this->overlapping($req, ['weekend', 'zimmer_desk', 'woche', 'tagesplatz', 'haus']);
  }

  public function isHouseAvailable(\DateTime $start, \DateTime $end): bool {
    if ($this->isBeforeOpening($start)) return FALSE;
    $req = $this->interval('haus', $start->format('Y-m-d'), $end->format('Y-m-d'));
    return !$this->overlapping($req, ['weekend', 'zimmer_desk', 'woche', 'tagesplatz', 'haus']);
  }

  public function getFreeRooms(\DateTime $date): array {
    $all = range(1, (int) ($this->config->get('room_count') ?? 9));
    $ds  = $date->format('Y-m-d');
    $req = $this->interval('zimmer_desk', $ds, (clone $date)->modify('+1 day')->format('Y-m-d'));
    if ($this->overlapping($req, ['weekend', 'haus'])) return [];
    $taken = array_map(fn($n) => (int) $n->get('field_gh_room_number')->value,
      $this->overlapping($req, ['zimmer_desk', 'woche']));
    return array_values(array_diff($all, $taken));
  }

  public function getFreeTagesplaetze(\DateTime $date): int {
    $max = (int) ($this->config->get('max_tagesplaetze') ?? 8);
    $ds  = $date->format('Y-m-d');
    $req = $this->interval('tagesplatz', $ds, (clone $date)->modify('+1 day')->format('Y-m-d'));
    if ($this->overlapping($req, ['weekend', 'haus'])) return 0;
    return max(0, $max - count($this->overlapping($req, ['tagesplatz'])));
  }

  public function isRoomAvailable(int $room, \DateTime $start, \DateTime $end, string $type = 'zimmer_desk'): bool {
    if ($this->isBeforeOpening($start)) return FALSE;
    $req = $this->interval($type, $start->format('Y-m-d'), $end->format('Y-m-d'));
    if ($this->overlapping($req, ['weekend', 'haus'])) return FALSE;
    return !$this->overlapping($req, ['zimmer_desk', 'woche'], $room);
  }
}
