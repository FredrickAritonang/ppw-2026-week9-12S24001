<?php

namespace App\Http\Controllers\Soap;

use App\Http\Controllers\Controller;
use App\Models\Peminjaman;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Layanan SOAP ringkas untuk sistem legacy Administrasi Yudisium.
 * Operasi: CheckClearanceStatus (cek tanggungan buku / status bebas pustaka).
 */
class LibrarySoapController extends Controller
{
    private const NS = 'http://sipus.del.ac.id/soap/library';

    /**
     * GET /soap/sipus.wsdl : kontrak formal WSDL.
     */
    public function wsdl(): Response
    {
        $ns = self::NS;
        $endpoint = url('/soap/sipus');

        $wsdl = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<definitions name="LibrarySoapService"
    targetNamespace="{$ns}"
    xmlns:tns="{$ns}"
    xmlns:soap="http://schemas.xmlsoap.org/wsdl/soap/"
    xmlns:xsd="http://www.w3.org/2001/XMLSchema"
    xmlns="http://schemas.xmlsoap.org/wsdl/">

    <!-- 1. Message: parameter input dan output -->
    <message name="CheckClearanceStatusRequest">
        <part name="nim_peminjam" type="xsd:string"/>
    </message>
    <message name="CheckClearanceStatusResponse">
        <part name="nim_peminjam" type="xsd:string"/>
        <part name="active_loans" type="xsd:int"/>
        <part name="is_cleared" type="xsd:boolean"/>
    </message>

    <!-- 2. PortType: daftar operasi -->
    <portType name="LibraryPortType">
        <operation name="CheckClearanceStatus">
            <input message="tns:CheckClearanceStatusRequest"/>
            <output message="tns:CheckClearanceStatusResponse"/>
        </operation>
    </portType>

    <!-- 3. Binding: ikat operasi ke SOAP over HTTP -->
    <binding name="LibrarySoapBinding" type="tns:LibraryPortType">
        <soap:binding style="rpc" transport="http://schemas.xmlsoap.org/soap/http"/>
        <operation name="CheckClearanceStatus">
            <soap:operation soapAction="{$ns}#CheckClearanceStatus"/>
            <input><soap:body use="literal" namespace="{$ns}"/></input>
            <output><soap:body use="literal" namespace="{$ns}"/></output>
        </operation>
    </binding>

    <!-- 4. Service: alamat fisik endpoint -->
    <service name="LibrarySoapService">
        <port name="LibraryPort" binding="tns:LibrarySoapBinding">
            <soap:address location="{$endpoint}"/>
        </port>
    </service>
</definitions>
XML;

        return response($wsdl, 200)->header('Content-Type', 'text/xml; charset=utf-8');
    }

    /**
     * POST /soap/sipus : memproses <soap:Envelope> berisi <nim_peminjam>.
     */
    public function handle(Request $request): Response
    {
        $rawXml = trim($request->getContent());

        if ($rawXml === '') {
            return $this->soapFault('soap:Client', 'Body XML SOAP tidak boleh kosong.');
        }

        // Tolak DTD/entity eksternal (mencegah XXE & billion laughs) pada layanan publik.
        if (stripos($rawXml, '<!DOCTYPE') !== false || stripos($rawXml, '<!ENTITY') !== false) {
            return $this->soapFault('soap:Client', 'Deklarasi DOCTYPE/ENTITY tidak diizinkan.');
        }

        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($rawXml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($xml === false) {
            return $this->soapFault('soap:Client', 'Format XML tidak valid (Malformed XML).');
        }

        // Pastikan operasi yang dipanggil adalah CheckClearanceStatusRequest (abaikan prefix namespace).
        $request_nodes = $xml->xpath('//*[local-name()="CheckClearanceStatusRequest"]');
        if (empty($request_nodes)) {
            return $this->soapFault('soap:Client', 'Operasi tidak dikenali. Gunakan CheckClearanceStatusRequest.');
        }

        $nimNodes = $xml->xpath('//*[local-name()="CheckClearanceStatusRequest"]/*[local-name()="nim_peminjam"]');
        $nim = empty($nimNodes) ? '' : trim((string) $nimNodes[0]);

        if ($nim === '') {
            return $this->soapFault('soap:Client', 'Elemen <nim_peminjam> wajib diisi dan tidak boleh kosong.');
        }

        // Hitung pinjaman aktif (status = 'dipinjam') milik NIM tersebut.
        $activeLoans = Peminjaman::aktif()->where('nim_peminjam', $nim)->count();
        $isCleared = $activeLoans === 0 ? 'true' : 'false';

        $safeNim = htmlspecialchars($nim, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $ns = self::NS;

        $response = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"
    xmlns:m="{$ns}">
    <soap:Header>
        <m:ProviderInfo>SIPUS-Del Legacy SOAP Engine v1.0</m:ProviderInfo>
    </soap:Header>
    <soap:Body>
        <m:CheckClearanceStatusResponse>
            <m:nim_peminjam>{$safeNim}</m:nim_peminjam>
            <m:active_loans>{$activeLoans}</m:active_loans>
            <m:is_cleared>{$isCleared}</m:is_cleared>
        </m:CheckClearanceStatusResponse>
    </soap:Body>
</soap:Envelope>
XML;

        return response($response, 200)->header('Content-Type', 'text/xml; charset=utf-8');
    }

    /**
     * Pembentuk <soap:Fault> standar protokol SOAP 1.1.
     */
    private function soapFault(string $faultCode, string $faultMessage): Response
    {
        $safeMsg = htmlspecialchars($faultMessage, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        $fault = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
    <soap:Body>
        <soap:Fault>
            <faultcode>{$faultCode}</faultcode>
            <faultstring>{$safeMsg}</faultstring>
        </soap:Fault>
    </soap:Body>
</soap:Envelope>
XML;

        return response($fault, 500)->header('Content-Type', 'text/xml; charset=utf-8');
    }
}