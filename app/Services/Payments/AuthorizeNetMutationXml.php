<?php

namespace App\Services\Payments;

use DOMElement;
use DOMXPath;
use Illuminate\Validation\ValidationException;

/** Strict parser only: this helper has no network capability. */
final class AuthorizeNetMutationXml
{
    public const NS = 'AnetApi/xml/v1/schema/AnetApiSchema.xsd';

    public const MAX_BYTES = 262144;

    public function element(DOMXPath $xml, string $parent, string $name, bool $optional = false): ?DOMElement
    {
        $nodes = $xml->query($parent.'/*[local-name()="'.$name.'"]');
        if ($optional && $nodes->length === 0) {
            return null;
        }
        if ($nodes->length !== 1 || $nodes->item(0)->namespaceURI !== self::NS || $nodes->item(0)->hasAttributes()) {
            $this->reject();
        }

        return $nodes->item(0);
    }

    public function value(DOMXPath $xml, string $parent, string $name, bool $optional = false): ?string
    {
        $node = $this->element($xml, $parent, $name, $optional);
        if ($node === null) {
            return null;
        }
        foreach ($node->childNodes as $child) {
            if ($child->nodeType !== XML_TEXT_NODE) {
                $this->reject();
            }
        }
        if (strlen($node->textContent) > 128) {
            $this->reject();
        }

        return $node->textContent;
    }

    public function reject(): never
    {
        throw ValidationException::withMessages(['gateway_transport' => 'Gateway transport scope or response could not be verified.']);
    }
}
