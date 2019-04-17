<?php

$xml = '<?xml version="1.0" encoding="UTF-8"?>
<Request
    xmlns="urn:oasis:names:tc:xacml:2.0:context:schema:os">
    <Subject SubjectCategory="urn:oasis:names:tc:xacml:1.0:subject-category:access-subject">
        <Attribute AttributeId="urn:oasis:names:tc:xacml:1.0:subject:role-id"
                   DataType="http://www.w3.org/2001/XMLSchema#string" Issuer="requestor">
            <AttributeValue>CISCO:UC:UCMPolicy</AttributeValue>
        </Attribute>
        <Attribute AttributeId="urn:Cisco:uc:1.0:callingnumber"
                   DataType="http://www.w3.org/2001/XMLSchema#string">
            <AttributeValue>+12028055054</AttributeValue>
        </Attribute>
        <Attribute AttributeId="urn:Cisco:uc:1.0:callednumber"
                   DataType="http://www.w3.org/2001/XMLSchema#string">
            <AttributeValue>88109</AttributeValue>
        </Attribute>
        <Attribute AttributeId="urn:Cisco:uc:1.0:transformedcgpn"
                   DataType="http://www.w3.org/2001/XMLSchema#string">
            <AttributeValue>+12028055054</AttributeValue>
        </Attribute>
        <Attribute AttributeId="urn:Cisco:uc:1.0:transformedcdpn"
                   DataType="http://www.w3.org/2001/XMLSchema#string">
            <AttributeValue>88109</AttributeValue>
        </Attribute>
        <Attribute AttributeId="urn:Cisco:uc:1.0:callingdevicename" DataType="http://www.w3.org/2001/XMLSchema#string">
            <AttributeValue>CSR1000v</AttributeValue>
        </Attribute>
    </Subject>
    <Resource>
        <Attribute AttributeId="urn:oasis:names:tc:xacml:1.0:resource:resource-id"
                   DataType="http://www.w3.org/2001/XMLSchema#anyURI">
            <AttributeValue>CISCO:UC:VoiceOrVideoCall</AttributeValue>
        </Attribute>
    </Resource>
    <Action>
        <Attribute AttributeId="urn:oasis:names:tc:xacml:1.0:action:action-id"
                   DataType="http://www.w3.org/2001/XMLSchema#anyURI">
            <AttributeValue>any</AttributeValue>
        </Attribute>
    </Action>
    <Environment>
        <Attribute AttributeId="urn:Cisco:uc:1.0:triggerpointtype"
                   DataType="http://www.w3.org/2001/XMLSchema#string">
            <AttributeValue>directorynumber</AttributeValue>
        </Attribute>
    </Environment>
</Request>';

$simpleXml = new SimpleXMLElement($xml);

print_r((string) $simpleXml->Subject->Attribute[1]->AttributeValue);

