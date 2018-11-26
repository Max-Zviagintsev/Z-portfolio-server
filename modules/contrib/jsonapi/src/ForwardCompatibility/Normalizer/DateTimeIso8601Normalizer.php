<?php

namespace Drupal\jsonapi\ForwardCompatibility\Normalizer;

use Drupal\Core\TypedData\Plugin\DataType\DateTimeIso8601;
use Drupal\datetime\Plugin\Field\FieldType\DateTimeItem;

/**
 * Converts values for the DateTimeIso8601 data type to RFC3339.
 *
 * @internal
 * @see \Drupal\serialization\Normalizer\DateTimeIso8601Normalizer
 * @todo Remove when JSON:API requires Drupal 8.6.
 */
class DateTimeIso8601Normalizer extends DateTimeNormalizer {

  /**
   * {@inheritdoc}
   */
  protected $allowedFormats = [
    // RFC3339 only covers combined date and time representations. For date-only
    // representations, we need to use ISO 8601. There isn't a constant on the
    // \DateTime class that we can use, so we have to hardcode the format.
    // @see https://en.wikipedia.org/wiki/ISO_8601#Calendar_dates
    // @see \Drupal\datetime\Plugin\Field\FieldType\DateTimeItemInterface::DATE_STORAGE_FORMAT
    'date-only' => 'Y-m-d',
  ];

  /**
   * {@inheritdoc}
   */
  protected $supportedInterfaceOrClass = DateTimeIso8601::class;

  /**
   * {@inheritdoc}
   */
  public function normalize($datetime, $format = NULL, array $context = []) {
    $field_item = $datetime->getParent();
    if ($field_item instanceof DateTimeItem && $field_item->getFieldDefinition()->getFieldStorageDefinition()->getSetting('datetime_type') === DateTimeItem::DATETIME_TYPE_DATE) {
      return $datetime->getDateTime()->format($this->allowedFormats['date-only']);
    }
    return parent::normalize($datetime, $format, $context);
  }

  /**
   * {@inheritdoc}
   */
  public function denormalize($data, $class, $format = NULL, array $context = []) {
    $date = parent::denormalize($data, $class, $format, $context);
    // Extract the year, month, and day from the object.
    $ymd = $date->format('Y-m-d');
    // Rebuild the date object using the extracted year, month, and day, but
    // for consistency set the time to 12:00:00 UTC upon creation for date-only
    // fields. Rebuilding, instead of using the object methods, is done to
    // avoid the initial date object picking up the local time and time zone
    // from an input value with a missing or partial time string, and then
    // rolling over to a different day when changing the object to UTC.
    // @see \Drupal\Component\Datetime\DateTimePlus::setDefaultDateTime()
    // @see \Drupal\datetime\Plugin\views\filter\Date::getOffset()
    // @see \Drupal\datetime\DateTimeComputed::getValue()
    // @see http://php.net/manual/en/datetime.createfromformat.php
    return \DateTime::createFromFormat('Y-m-d\TH:i:s e', $ymd . 'T12:00:00 UTC');
  }

}
