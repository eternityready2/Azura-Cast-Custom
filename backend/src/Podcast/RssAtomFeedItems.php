<?php

declare(strict_types=1);

namespace App\Podcast;

use SimpleXMLElement;

/**
 * RSS 2.0 channel items and Atom entries, matching import/preview behaviour.
 */
final class RssAtomFeedItems
{
    /**
     * @return list<SimpleXMLElement>
     */
    public static function fromParsedXml(SimpleXMLElement $xml): array
    {
        $items = [];
        if (isset($xml->channel->item)) {
            foreach ($xml->channel->item as $item) {
                $items[] = $item;
            }
        }
        if (isset($xml->entry)) {
            foreach ($xml->entry as $entry) {
                $items[] = $entry;
            }
        }

        return $items;
    }
}
