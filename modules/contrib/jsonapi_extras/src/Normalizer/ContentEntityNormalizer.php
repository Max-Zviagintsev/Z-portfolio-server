<?php

namespace Drupal\jsonapi_extras\Normalizer;

use Drupal\jsonapi\Normalizer\ContentEntityNormalizer as JsonapiContentEntityNormalizer;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Override ContentEntityNormalizer to prepare input.
 */
class ContentEntityNormalizer extends JsonapiContentEntityNormalizer {

  use EntityNormalizerTrait;

  /**
   * {@inheritdoc}
   */
  public function setSerializer(SerializerInterface $serializer) {
    // The first invocation is made by the container builder, it respects the
    // service definition. We respect this.
    // The second invocation is made by the Serializer service constructor, it
    // does not respect the service definition. We ignore this call.
    if (!isset($this->serializer)) {
      parent::setSerializer($serializer);
    }
  }

}
