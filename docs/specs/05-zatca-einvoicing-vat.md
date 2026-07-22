# 05 — ZATCA Phase-2 E-Invoicing (KSA) + VAT Compliance (KSA & UAE)

> **Status:** Draft spec, implementation-ready.
> **Owner:** Backend / Compliance
> **Related:** Profit & Cost Engine spec (`order_fees`, VAT-inclusive pricing, COGS). That document owns fee/COGS/margin modelling and VAT-inclusive price decomposition. **This spec does not redefine those**; it consumes them.
> **Legal warning:** This is a regulatory-compliance feature. Every requirement below is tagged **[VERIFIED]** (with a source URL) or **[UNVERIFIED]** (assumption / needs confirmation before code ships). Do not promote an **[UNVERIFIED]** item to implementation without confirming it against ZATCA's current published standards or a tax advisor.

---

## 1. Why this exists (regulatory + competitive rationale)

### Regulatory

Saudi Arabia's e-invoicing mandate has two phases. Phase 1 ("Generation", from 4 December 2021) required structured electronic invoices with a QR code. Phase 2 ("Integration") additionally requires every taxpayer's invoicing system (an "EGS" — E-Invoice Generation Solution) to integrate directly with ZATCA's **Fatoora** platform over API, cryptographically stamp each document, and either **clear** (B2B) or **report** (B2C) it. **[VERIFIED]** — [ZATCA E-Invoicing Detailed Guideline v2, §6.4](https://zatca.gov.sa/en/E-Invoicing/Introduction/Guidelines/Documents/E-Invoicing_Detailed__Guideline.pdf)

Phase 2 has rolled out in waves by revenue threshold. **Wave 24 — announced 26 September 2025, covering taxpayers with VAT-taxable revenue above SAR 375,000 in 2022, 2023 or 2024 — had a compliance deadline of 30 June 2026.** SAR 375,000 is also the mandatory VAT-registration threshold, so the wave programme has now effectively swept in **every VAT-registered business in KSA**. **[VERIFIED — secondary sources; confirm current wave status on ZATCA's newsroom before go-live]** — [ZATCA Wave 24 announcement](https://zatca.gov.sa/en/Pages/news_1426.aspx), [VATupdate — Wave 24 compliance by 30 June 2026](https://www.vatupdate.com/2026/06/30/saudi-arabia-ksa-zatca-phase-2-wave-24-compliance-by-30-june-2026/)

The practical consequence: **a VAT-registered Saudi merchant cannot legally use an invoicing system of record that does not do ZATCA Phase 2.**

### Competitive

Linnworks, Rithum (ChannelAdvisor) and Sellerboard have no ZATCA Phase-2 capability. A Saudi merchant evaluating them hits a hard legal wall — they would need a *second* system for invoicing, which defeats the point of a system of record. Per `docs/COMPETITIVE_STRATEGY.md` §"Where we win", this is called out as **"the highest-leverage item on this list"** and a regulatory moat.

This is also a moat with a **time cost to copy**: onboarding requires per-taxpayer cryptographic certificates issued by ZATCA's CA, a compliance-check pass, and an audited chain of custody. A competitor cannot ship it in a quarter.

### Strategic framing

- ZATCA compliance is the **wedge** (merchant legally must have it).
- Profit truth is the **retention** (merchant wants it).
- Together they make Hubby the system of record rather than a sync tool.

---

## 2. Scope — in / out

### In scope

| Item | Detail |
|---|---|
| **KSA ZATCA Phase 2 (Integration)** | Standard (B2B) clearance + Simplified (B2C) reporting, for tax invoices, credit notes and debit notes |
| **KSA Phase 1 fallback** | Compliant QR + XML generation for orgs not yet onboarded to Phase 2 (see §13 rollout) |
| **Onboarding** | CSR generation, OTP entry, Compliance CSID, compliance checks, Production CSID, renewal, revocation handling |
| **Invoice artefacts** | UBL 2.1 XML, XAdES B-B cryptographic stamp, TLV/base64 QR, ICV, PIH chain, UUID |
| **Documents** | Tax invoice (388), credit note (381), debit note (383) |
| **Rendering** | Bilingual (Arabic/English) human-readable PDF with embedded QR |
| **Archival** | Immutable storage of signed XML + ZATCA responses |
| **UAE VAT** | 5% VAT calculation, TRN capture and validation, FTA-compliant tax invoice content and bilingual rendering |
| **Channel arbitration** | Determining, per channel, whether Hubby issues the invoice or the marketplace does |

### Out of scope (this spec)

| Item | Why / where |
|---|---|
| **Prepayment invoices (386)** | Rare in the target e-commerce flow. Add later; the data model reserves the type code. |
| **Self-billed invoices** | Not applicable to the merchant-sells-to-consumer flow. |
| **Export / zero-rated / exempt edge handling beyond basic category codes** | Needs tax-advisor input; Phase 2 of this feature. |
| **UAE e-invoicing (PINT AE / Peppol 5-corner)** | Mandate begins **1 Jan 2027** for AED 50m+ revenue, with a voluntary pilot from **1 July 2026**; requires an FTA-**Accredited Service Provider (ASP)**, which Hubby is not. **[VERIFIED — secondary sources]** — [ClearTax UAE e-invoicing](https://www.cleartax.com/ae/e-invoicing-uae), [Banqup — UAE phased rollout](https://www.banqup.com/resources/blog/uae-confirms-phased-e-invoicing-mandate-rollout). **This spec covers UAE *VAT invoice content* only, not UAE e-invoicing transmission.** See §16 Open Questions. |
| **Turkey e-Fatura / e-Arşiv (Trendyol)** | Separate national regime, separate spec. See §7. |
| **VAT return filing / submission** | We produce the data; filing is out of scope. |
| **Bahrain, Oman, Qatar, Kuwait VAT** | Future. |
| **COGS, fees, margin** | Owned by the Profit & Cost Engine spec. |

### Countries

- **Saudi Arabia (SA)** — full Phase 2.
- **United Arab Emirates (AE)** — VAT invoice content compliance only.
- All other countries — invoices are generated as plain (non-fiscal) documents; no tax authority submission.

---

## 3. Regulatory requirements summary

### 3.1 Primary sources

| Document | URL |
|---|---|
| E-Invoicing Detailed Guideline v2 (May 2023) | https://zatca.gov.sa/en/E-Invoicing/Introduction/Guidelines/Documents/E-Invoicing_Detailed__Guideline.pdf |
| Electronic Invoice **XML Implementation Standard** v1.2 (2023-05-19) | https://zatca.gov.sa/ar/E-Invoicing/SystemsDevelopers/Documents/20230519_ZATCA_Electronic_Invoice_XML_Implementation_Standard_%20vF.pdf |
| Electronic Invoice **Security Features Implementation Standards** v1.2 (2023-05-19) | https://zatca.gov.sa/ar/E-Invoicing/SystemsDevelopers/Documents/20230519_ZATCA_Electronic_Invoice_Security_Features_Implementation_Standards_vF.pdf |
| E-Invoicing Detailed **Technical** Guidelines (FATOORA portal / onboarding) | https://zatca.gov.sa/en/E-Invoicing/Introduction/Guidelines/Documents/E-invoicing-Detailed-Technical-Guideline.pdf |
| Fatoora Developer Community (API endpoints) | https://zatca1.discourse.group/t/e-invoicing-api-endpoints/487 |
| ZATCA E-Invoicing portal | https://zatca.gov.sa/en/E-Invoicing/Pages/default.aspx |

> All ZATCA quotes below were extracted from the PDFs above. Section numbers refer to those documents.

### 3.2 Standard vs Simplified — the core split

| | **Standard Tax Invoice** | **Simplified Tax Invoice** |
|---|---|---|
| Typical use | B2B (seller → registered business) | B2C (seller → consumer) |
| Flow | **Clearance** | **Reporting** |
| Timing | **Real-time, before sharing with the buyer** | **Within 24 hours of issuance** |
| ZATCA's role | Validates and **stamps** the invoice; returns the cleared XML | Validates and acknowledges |
| Legal effect | *"Each Tax Invoice generated electronically must be cleared by the Authority as a prerequisite for sharing them with the buyers and for such Electronic Invoice to be regarded as legal and valid."* | Invoice is issued to the customer immediately; reporting is after the fact |
| Buyer details | Buyer name, address (street, city, country) and VAT number required | Not required (fewer mandatory fields, per KSA VAT Regulation Art. 53(8)) |
| Submission format | XML **or** PDF/A-3 with embedded XML | **XML only** |
| QR TLV tag 9 | Not applicable | **Required** |

**[VERIFIED]** — Detailed Guideline §6.4; XML Implementation Standard §11.2.1, BR-KSA-10..14.

Clearance applies to tax invoices **and their associated credit/debit notes**. Reporting likewise covers simplified credit/debit notes. **[VERIFIED]** — Detailed Guideline §6.4.

### 3.3 Invoice type codes

`cbc:InvoiceTypeCode` carries a UN/CEFACT 1001 code as its value and a 7-character `name` attribute whose **first two characters** encode the KSA subtype (`01` = standard, `02` = simplified). **[VERIFIED]** — XML Implementation Standard §11.2.1:

| Document | Code | Standard (`01`) | Simplified (`02`) |
|---|---|---|---|
| Tax invoice | `388` | `<cbc:InvoiceTypeCode name="0100000">388</cbc:InvoiceTypeCode>` | `name="0200000"` |
| Debit note | `383` | `name="0100000"` | `name="0200000"` |
| Credit note | `381` | `name="0100000"` | `name="0200000"` |
| Prepayment invoice | `386` | `name="0100000"` | `name="0200000"` |

**[UNVERIFIED — must confirm before coding]** The meaning of `name` characters **3–7**. The standard states only that *"additional flags indicating transaction type have been added as the final four positions"* — which conflicts arithmetically with a 7-character attribute where the first two are the subtype (2 + 4 = 6, not 7). The commonly-cited industry mapping is `NNPNESB` (positions 3–7 = third-party / nominal / exports / summary / self-billed). **Action: confirm against the ZATCA E-Invoicing Data Dictionary and the schematron validation rules before implementing anything other than the all-zeros default `0100000` / `0200000`.**

### 3.4 KSA-specific business terms

**[VERIFIED]** — XML Implementation Standard, BR-KSA rules:

| Term | Meaning | UBL location |
|---|---|---|
| **KSA-1 (UUID)** | Unique document identifier, UUID v4 | `/ubl:Invoice/cbc:UUID` |
| **KSA-2** | Invoice transaction code (the `name` attribute above) | `/ubl:Invoice/cbc:InvoiceTypeCode/@name` |
| **KSA-13 (PIH)** | Previous Invoice Hash | `/ubl:Invoice/cac:AdditionalDocumentReference` where `cbc:ID = 'PIH'`, in `cac:Attachment/cbc:EmbeddedDocumentBinaryObject` |
| **KSA-14 (QR)** | QR code, base64Binary | `cac:AdditionalDocumentReference` where `cbc:ID = 'QR'` |
| **KSA-15** | Cryptographic stamp | `/ubl:Invoice/cac:Signature` + `ext:UBLExtensions` |
| **KSA-16 (ICV)** | Invoice Counter Value | `cac:AdditionalDocumentReference` where `cbc:ID = 'ICV'`, in `cbc:UUID` |
| **BT-110** | Invoice total VAT amount (also QR tag 5) | `/ubl:Invoice/cac:TaxTotal/cbc:TaxAmount` |

### 3.5 Cryptographic stamp

**[VERIFIED]** — Security Features Implementation Standards §2.2.1, §2.3:

- **Signature format:** XAdES (ETSI EN 319 132-1) for XML; PAdES (EN 319 142-1) for PDF/A-3.
- **Signature level:** **B-B** (basic — mandatory qualifying properties only).
- **Packaging:** **enveloped** — the signature is a sub-element of the signed XML.
- **Hashing algorithm:** **SHA-256**.
- **Asymmetric algorithm:** **ECDSA**, key length **256**.
- **Data to be signed:** *"the whole XML content except the QR-code data element"*.
- **Certificate chain:** the full chain from the signing certificate up to ZATCA's trust anchor **shall be included in the signature**.
- **Key protection:** keys **must be marked non-exportable** where the security module supports it; software modules must use disk encryption at minimum. A software-based module is explicitly permitted.
- **Certificate validity:** up to **60 months (5 years)** per ZATCA's illustrative X.509 profile.
- **Revocation:** CRLs are valid **7 days**, allowing an EGS to work fully offline for 7 days before it must refresh the CRL.
- **Time of signing:** taken from the EGS clock (claimed signing time).

**[UNVERIFIED — high risk, confirm first]** **The elliptic curve.** The v1.2 Security Features standard text states only *"ECDSA … key length shall be 256"*, and its illustrative certificate-profile table renders as `P-256`. However, ZATCA's sandbox and every mainstream implementation use **`secp256k1`** for the EGS key pair — see a Fatoora developer-community thread where a working configuration is described as *"Key Algorithm: secp256k1"* with `ecdsa-with-SHA256`: https://zatca1.discourse.group/t/sandbox-compliance-csid-api-returns-400-invalid-request-with-externally-generated-csr/9948. **Action: verify empirically against the sandbox Compliance CSID API during Milestone 1 (§13) and record the answer here. Getting this wrong makes every certificate request fail.**

**Signature transform (used for both the stamp and the PIH).** BR-KSA-27 specifies removing, before canonicalisation and hashing:

1. the `<ext:UBLExtensions>` block,
2. the `<cac:AdditionalDocumentReference>` block where `cbc:ID = 'QR'`,
3. the `<cac:Signature>` block.

Then canonicalise (XML C14N) and SHA-256. **[VERIFIED]** — XML Implementation Standard BR-KSA-27; canonicalisation algorithm named in the `ds:SignedInfo`/`ds:CanonicalizationMethod` per §2.3.3. **[UNVERIFIED]** the exact C14N variant URI (inclusive vs exclusive, with/without comments) — confirm against ZATCA's reference SDK.

### 3.6 Previous Invoice Hash (PIH)

> *"The hash of the previous invoice is generated by applying the same transform as is used for the cryptographic stamp and as specified in section 2.3.3 and taking the sha256 algorithm."*

**[VERIFIED]** — Security Features Implementation Standards §3.

**[UNVERIFIED]** The genesis PIH value for the first invoice in a chain. The universally-used value is the base64 of the SHA-256 of the character `0`, i.e. `NWZlY2ViNjZmZmM4NmYzOGQ5NTI3ODZjNmQ2OTZjNzljMmRiYzIzOWRkNGU5MWI0NjcyOWQ3M2EyN2ZiNTdlOQ==`. The XML Implementation Standard alludes to a `"0"` (zero) character (BR-KSA rule text near KSA-13). **Action: confirm against the ZATCA SDK before go-live.**

### 3.7 QR code

**[VERIFIED]** — Security Features Implementation Standards §4:

- Encoded in **TLV** (Tag-Length-Value), then **base64**, **up to 700 characters**.
- Encoding per field: `Tag` = 1 byte; `Length` = length of the UTF-8 byte array as an **unsigned 8-bit integer**, 1 byte; `Value` = the UTF-8 byte array. For **tag 6** the length is the 32-byte SHA-256 hash.
- Order of operations: build values → construct TLV tuples in tag order → concatenate byte array → base64 → render QR image.

| Tag | Field | Notes |
|---|---|---|
| 1 | Seller's name | |
| 2 | VAT registration number of the seller | |
| 3 | Invoice timestamp, ISO 8601 | e.g. `2022-02-21T12:13:57Z` |
| 4 | Invoice total (with VAT) | |
| 5 | VAT total | value of BT-110 |
| 6 | Hash of XML invoice | SHA-256, 32 bytes |
| 7 | ECDSA signature of the XML hash | |
| 8 | ECDSA public key extracted from the signing private key | |
| 9 | **Simplified invoices and their notes only** — ECDSA signature of the cryptographic stamp's public key, issued by ZATCA's technical CA | |

Tags 1–2 were enforced from 4 Dec 2021 and 1 Jan 2023 respectively; tags 6–9 exist only in Phase 2.

> **Ordering hazard:** tags 6–9 depend on the *signed* XML, so the QR must be produced **after** signing. But the QR element is one of the blocks *removed* before hashing (§3.5), so there is no circular dependency — build XML → strip → canonicalise → hash → sign → build TLV → inject QR into `cac:AdditionalDocumentReference[ID='QR']`.

### 3.8 Rounding

**[VERIFIED]** — XML Implementation Standard §10:

- Rounding is **half-up** ("half-way values are always rounded up").
- All **document-level totals** are rounded to **two decimals**.
- *"Rounding shall be done on the final calculation results not on any intermediate results."*
- *"VAT category tax amount (BT-110) shall be rounded on document level and not as a summation of rounded Invoice line VAT amounts."*
- Four-decimal rounding uses the fifth decimal.

### 3.9 Immutability and cancellation

An E-Invoice Solution **must not** allow alteration or deletion of generated e-invoices or their associated notes:

> *"Persons subject to the E-Invoicing Regulation are not allowed to modify or delete invoices once they are issued whether these are generated by the system or outside it. If a user wishes to 'cancel' an invoice, this may only be done through issuing an associated credit note and reissuance of a new invoice."*

Other prohibited functions: anonymous access, default/factory passwords, absence of user session management, and modification or deletion of system logs. **[VERIFIED]** — Detailed Guideline §5.6, §6.5.

### 3.10 Rejection and chain behaviour

**[VERIFIED]** — Detailed Technical Guidelines, FAQ section:

- *"After an invoice gets rejected UUID and ICV should not be re-used. System should assign a new ICV when the document is submitted after fixing errors."*
- On reporting rejection: *"the taxpayer can fix the error and re-submit … The new invoice should include its own new unique hash, UUID, invoice counter value and timestamp. The date on invoice should remain as when transaction took place. **Previous Invoice Hash will be based on immediately preceding document (not necessarily linked to the rejected invoice).**"*
- On `status: NOT_REPORTED`: *"the error should be checked, the invoice should be cancelled via a credit note and a new invoice generated. Once 'Not Reported' the invoice is deemed invalid."*

This last one resolves the hardest design question: **the PIH chain follows the sequence of documents we generated, and a rejected document does not orphan the chain** — the next document chains off the immediately preceding document.

### 3.11 Downtime / failure behaviour

**[VERIFIED]** — Detailed Guideline, "Failure to receive response from ZATCA" scenarios:

- **B2B (clearance) outage:** retry for ~5 minutes; if ZATCA remains non-responsive, **share the uncleared invoice** with the buyer, keep records of the transaction (Art. 7.5) and confirm the buyer's contact details; keep retrying at regular intervals (~every 15 minutes). *"Since ZATCA is aware when the servers are down, notifying ZATCA is not required."* The uncleared invoice *"will not be fully compliant but will be considered as VAT invoice until fully compliant invoice is issued immediately once the connection is back."*
- **B2C (reporting) outage:** *"the … server should continue attempting to report the simplified tax invoice in regular intervals until successful."* If not reported within 24 hours, the taxpayer *"should notify ZATCA via a dedicated form on ZATCA's website."*
- ZATCA's own document marks the 5-minute and 15-minute intervals as **"timing TBC"**, so these are guidance, not hard requirements.
- **[VERIFIED]** Taxpayers should keep evidence of having attempted to clear/report.

### 3.12 Authentication

> *"ZATCA is going to leverage OAuth 2.0 … particularly 'OAuth 2 Basic Authentication' as specified in RFC6749. The Client ID will be the digital certificate issued as part of the onboarding process. The Secret Value will additionally be issued as part of the onboarding process."*

**[VERIFIED]** — Security Features Implementation Standards §5. In practice this is an HTTP `Authorization: Basic base64(<binarySecurityToken>:<secret>)` header.

### 3.13 API endpoints

**[VERIFIED]** — [Fatoora Developer Community — E-Invoicing API endpoints](https://zatca1.discourse.group/t/e-invoicing-api-endpoints/487):

| Environment | Base URL |
|---|---|
| **Production ("core")** | `https://gw-fatoora.zatca.gov.sa/e-invoicing/core` |
| **Simulation** | `https://gw-fatoora.zatca.gov.sa/e-invoicing/simulation` |
| **Sandbox / developer portal** | `https://gw-fatoora.zatca.gov.sa/e-invoicing/developer-portal` |

| API | Path (relative to base) | Method |
|---|---|---|
| Compliance CSID (submit CSR) | `/compliance` | POST |
| Compliance checks (test invoices) | `/compliance/invoices` | POST |
| Production CSID (onboard / renew) | `/production/csids` | POST / PATCH |
| Clearance — single (standard) | `/invoices/clearance/single` | POST |
| Reporting — single (simplified) | `/invoices/reporting/single` | POST |

> The **sandbox** environment is a static test backend; the **simulation** environment mirrors production behaviour with non-live certificates. Certification/compliance rehearsal happens in simulation.

**[UNVERIFIED — confirm against the current Swagger on the ZATCA developer portal]** Exact request/response payloads and headers. Working assumption, to be validated in Milestone 1:

- Headers: `Accept-Version: V2`, `Content-Type: application/json`, `Accept-Language: en`, `Authorization: Basic …`; `OTP: <6 digits>` on the Compliance CSID call only.
- Compliance CSID request `{ "csr": "<base64 CSR>" }` → response `{ "requestID", "binarySecurityToken", "secret", "dispositionMessage" }`.
- Production CSID request `{ "compliance_request_id": "<requestID>" }` → response `{ "requestID", "binarySecurityToken", "secret" }`.
- Invoice submission request `{ "invoiceHash", "uuid", "invoice": "<base64 signed XML>" }`.
- Clearance response includes a `clearanceStatus` (e.g. `CLEARED` / `NOT_CLEARED`) and the **cleared, ZATCA-stamped XML**; reporting response includes a `reportingStatus` (e.g. `REPORTED` / `NOT_REPORTED`). Both carry `validationResults` with `warningMessages` and `errorMessages`.
- Clearance can be **switched off** by ZATCA, in which case the guideline instructs: *"Please submit via reporting"* — the client must handle a directive to fall back. **[VERIFIED that this state exists]** — Detailed Guideline.

### 3.14 OTP

OTPs are generated **only** on the FATOORA portal — there is no OTP API. They are valid for **1 hour**, and up to **100** can be requested at once. The OTP step is mandatory for onboarding and renewal. **[VERIFIED]** — Detailed Technical Guidelines FAQ.

**Design consequence:** onboarding **cannot** be fully automated. The merchant must log in to Fatoora, generate an OTP, and paste it into Hubby's wizard within the hour.

### 3.15 CSR subject fields

**[VERIFIED]** — Security Features Implementation Standards, Table 1:

| CSR input | X.509 field / OID | Content | Validation |
|---|---|---|---|
| Common Name | `CN` | Unique name or asset-tracking number of the solution unit | free text |
| EGS Serial Number | `subjectAltName` dirName `serialNumber` | `1-<Manufacturer/Provider>|2-<Model or Version>|3-<SerialNumber>` | must match that format |
| Organization Identifier | `organizationIdentifier` (**2.5.4.97**) | VAT / Group VAT registration number of the taxpayer | **15 digits, begins with 3 and ends with 3** |
| Organization Unit | `OU` | Branch name; **for VAT Groups, the 10-digit TIN of the group member whose device is onboarded** | 10-digit TIN validation for groups |
| Organization Name | `O` | Organization / taxpayer name | free text |
| Country | `C` | ISO 3166 alpha-2 | `SA` |
| Invoice Type | `businessCategory` (**2.5.4.15**) | 4-digit functionality map over `TSCZ`, `0`/`1` — T = standard tax invoice, S = simplified, C and Z reserved. e.g. `1100` = generates standard **and** simplified | 4 digits of 0/1 |
| Location | `registeredAddress` (**2.5.4.26**) | Address of the branch/location, or website address for e-commerce | free text |
| Industry | `businessCategory` in SAN | Industry/sector | free text |

The CSR must be **PKCS#10**, include at least the CN and public key, and be **signed with the private key as proof-of-possession**. **[UNVERIFIED]** The Microsoft template OID `1.3.6.1.4.1.311.20.2 = ZATCA-Code-Signing` is required in practice per the developer community, but is not in the Table 1 extract — confirm via sandbox.

> **Note for e-commerce:** ZATCA explicitly allows the "location" to be *"a website address for e-commerce"*, which is exactly Hubby's case.

### 3.16 Archival

ZATCA states that taxpayers *"may store their electronic invoices in a server on-premises in the KSA or in the cloud as per their solution requirements … and according to the provisions in VAT Law, VAT Implementing Regulation, E-Invoicing Regulation and Resolutions"*, and must be able to provide ZATCA auditors with the archived e-invoice and note files. **[VERIFIED]** — Detailed Guideline §5.5, §6.

**[UNVERIFIED — legal input required]** The **retention period**. The guideline defers to the VAT Regulations rather than stating a number. KSA VAT law is generally cited as **6 years**, extended for capital assets / real estate (commonly cited as 11 years). **Action: obtain a written answer from a KSA tax advisor and record it here before setting any data-retention or deletion policy.** Until then, **retain indefinitely** and never hard-delete.

**[UNVERIFIED]** Whether invoice data must be **stored inside KSA**. ZATCA's wording ("on-premises in the KSA or in the cloud") reads permissive, but data-residency expectations for cloud EGS providers should be confirmed. This materially affects hosting architecture. See §16.

### 3.17 UAE VAT

**[VERIFIED — secondary sources; confirm against FTA's VAT Executive Regulations Art. 59]**

- Standard VAT rate: **5%**.
- **TRN**: a **15-digit** Tax Registration Number issued by the FTA. Without a valid TRN on the invoice, it is not a valid tax invoice and the recipient cannot reclaim input VAT.
- A tax invoice must include: the words "Tax Invoice", supplier name/address/TRN, recipient name/address/TRN (for B2B), a sequential unique invoice number, date of issue, date of supply (if different), description of goods/services, unit price, quantity, rate of tax and amount payable in **AED**, discount, gross amount payable, and the tax amount payable in AED with the exchange rate applied where conversion occurred.
- Invoice numbers must be **sequential, unique and gap-free**.
- Tax invoices must be issued within **14 days** of the date of supply.
- **No clearance and no real-time reporting today.** UAE e-invoicing (Peppol 5-corner, PINT AE) is a separate future mandate — see §2 Out of scope.

Sources: [Wafeq — VAT invoice requirements in UAE](https://www.wafeq.com/en-ae/tax-and-reporting/vat-invoice-requirements-in-uae), [ClearTax — e-Invoicing UAE](https://www.cleartax.com/ae/e-invoicing-uae)

---

## 4. Data model

### 4.1 Conventions

- Laravel 12 migrations, repo convention: `YYYY_MM_DD_NNNNNN_verb_noun_table.php` (recent files use a `_0000NN` counter, e.g. `2026_07_02_000004_add_trendyol_to_stores_platform.php`).
- Multi-tenancy: **every fiscal table carries `organization_id` directly**, not via `stores`. `orders` currently reaches the tenant through `store_id` only; invoices are legal records and must not depend on a join for isolation.
- Money: `decimal(15,4)` for line-level working values, `decimal(15,2)` for document-level totals (§3.8 requires 2-dp document totals). Percentages: `decimal(5,2)`.
- Timestamps: `timestampTz` where a fiscal instant matters (QR tag 3 is an absolute ISO-8601 instant).

### 4.2 Migration list

| # | Migration file | Purpose |
|---|---|---|
| 1 | `2026_07_22_000001_create_tax_registrations_table.php` | Org tax registration per country |
| 2 | `2026_07_22_000002_create_zatca_egs_units_table.php` | EGS units (chain scope + CSID scope) |
| 3 | `2026_07_22_000003_create_zatca_certificates_table.php` | CSR / CCSID / PCSID / private key |
| 4 | `2026_07_22_000004_create_invoices_table.php` | Invoice header |
| 5 | `2026_07_22_000005_create_invoice_lines_table.php` | Invoice lines |
| 6 | `2026_07_22_000006_create_invoice_submissions_table.php` | Each submission attempt |
| 7 | `2026_07_22_000007_add_invoicing_columns_to_stores_table.php` | Per-channel issuer policy |

---

### 4.3 `tax_registrations`

One row per (organization, country). Holds the VAT identity used on invoices.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | `bigIncrements` | no | | |
| `organization_id` | `foreignId` → `organizations.id` | no | | `onDelete('cascade')` |
| `country_code` | `char(2)` | no | | ISO 3166-1 alpha-2: `SA`, `AE` |
| `legal_name` | `string(255)` | no | | Registered taxpayer name (English) |
| `legal_name_ar` | `string(255)` | yes | `null` | Arabic legal name — required for KSA invoice rendering |
| `vat_number` | `string(20)` | yes | `null` | KSA: 15 digits, starts and ends with `3`. UAE: 15-digit TRN |
| `tax_scheme` | `string(10)` | no | `'VAT'` | |
| `is_vat_group` | `boolean` | no | `false` | KSA VAT groups need the 10-digit member TIN in CSR `OU` |
| `group_member_tin` | `string(10)` | yes | `null` | Required when `is_vat_group` |
| `default_vat_rate` | `decimal(5,2)` | no | `15.00` | KSA 15.00, UAE 5.00 |
| `commercial_registration` | `string(50)` | yes | `null` | Other seller ID (CRN) |
| `street_name` | `string(255)` | yes | `null` | KSA standard invoices require seller address |
| `building_number` | `string(10)` | yes | `null` | KSA-17 |
| `plot_identification` | `string(10)` | yes | `null` | |
| `city_subdivision` | `string(255)` | yes | `null` | District |
| `city_name` | `string(255)` | yes | `null` | |
| `postal_zone` | `string(10)` | yes | `null` | |
| `country_subentity` | `string(255)` | yes | `null` | Province/region |
| `prices_include_tax` | `boolean` | no | `true` | Consumed from the Profit & Cost Engine; MENA storefronts are VAT-inclusive by default |
| `einvoicing_mode` | `enum('off','phase1','phase2')` | no | `'off'` | Drives which artefacts are generated |
| `environment` | `enum('sandbox','simulation','production')` | no | `'sandbox'` | ZATCA environment for this registration |
| `activated_at` | `timestampTz` | yes | `null` | When phase-2 went live for this org |
| `created_at` / `updated_at` | `timestamps` | | | |

**Indexes / constraints**
- `unique(['organization_id', 'country_code'])`
- `index('vat_number')`
- Check (application-level validation, not DB): KSA `vat_number` matches `/^3\d{13}3$/`.

---

### 4.4 `zatca_egs_units`

An **EGS unit** is what ZATCA issues a certificate to and what the ICV/PIH chain is scoped to. Modelled as its own table because one organization may legitimately have several (per branch, per storefront). **Default: one EGS unit per organization**; the wizard can create more.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | `bigIncrements` | no | | |
| `organization_id` | `foreignId` → `organizations.id` | no | | cascade |
| `tax_registration_id` | `foreignId` → `tax_registrations.id` | no | | restrict on delete |
| `store_id` | `foreignId` → `stores.id` | yes | `null` | Optional binding to one storefront; `null` = org-wide unit |
| `name` | `string(255)` | no | | CSR `CN` — unique solution-unit name |
| `egs_serial_number` | `string(255)` | no | | `1-Hubby|2-<version>|3-<uuid>` |
| `branch_name` | `string(255)` | yes | `null` | CSR `OU` |
| `registered_address` | `string(255)` | no | | CSR `registeredAddress` — storefront URL is acceptable |
| `industry` | `string(255)` | no | `'Retail'` | CSR industry |
| `invoice_type_map` | `char(4)` | no | `'1100'` | `TSCZ` functionality map |
| `status` | `enum('draft','csr_generated','compliance_csid','compliance_passed','production_csid','active','revoked','expired')` | no | `'draft'` | Onboarding state machine |
| `icv_counter` | `unsignedBigInteger` | no | `0` | Last allocated ICV for this unit |
| `last_invoice_hash` | `string(88)` | yes | `null` | Base64 SHA-256 of the previous document = next PIH |
| `last_invoice_id` | `foreignId` → `invoices.id` | yes | `null` | `nullOnDelete`; audit pointer for the chain head |
| `chain_broken_at` | `timestampTz` | yes | `null` | Set when an integrity check fails; blocks issuance |
| `onboarded_at` | `timestampTz` | yes | `null` | |
| `created_at` / `updated_at` | `timestamps` | | | |

**Indexes / constraints**
- `unique(['organization_id', 'egs_serial_number'])`
- `index(['organization_id', 'status'])`
- `index('store_id')`

> `icv_counter` and `last_invoice_hash` live here so a single `SELECT … FOR UPDATE` on this row atomically allocates both the counter and the chain link. See §5.3.

---

### 4.5 `zatca_certificates`

Certificate material for an EGS unit. Historical rows are retained — a signed invoice must remain verifiable against the certificate that signed it, even after renewal.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | `bigIncrements` | no | | |
| `organization_id` | `foreignId` → `organizations.id` | no | | cascade |
| `egs_unit_id` | `foreignId` → `zatca_egs_units.id` | no | | cascade |
| `type` | `enum('compliance','production')` | no | | CCSID or PCSID |
| `environment` | `enum('sandbox','simulation','production')` | no | | |
| `csr_pem` | `text` | yes | `null` | The PKCS#10 CSR (not secret) |
| `private_key_encrypted` | `text` | no | | **Encrypted at rest — see §4.9** |
| `certificate_pem` | `text` | yes | `null` | Decoded `binarySecurityToken` (public — not secret) |
| `binary_security_token` | `text` | yes | `null` | As returned by ZATCA; used as Basic-auth username |
| `api_secret_encrypted` | `text` | yes | `null` | **Encrypted at rest.** Basic-auth password |
| `zatca_request_id` | `string(64)` | yes | `null` | `requestID` from the compliance response; input to the PCSID call |
| `serial_number` | `string(64)` | yes | `null` | X.509 serial |
| `issuer_dn` | `string(512)` | yes | `null` | |
| `subject_dn` | `string(512)` | yes | `null` | |
| `not_before` | `timestampTz` | yes | `null` | |
| `not_after` | `timestampTz` | yes | `null` | Renewal alarm source (up to 5 years) |
| `status` | `enum('pending','active','superseded','revoked','expired')` | no | `'pending'` | |
| `revoked_at` | `timestampTz` | yes | `null` | |
| `created_at` / `updated_at` | `timestamps` | | | |

**Indexes / constraints**
- `index(['egs_unit_id', 'type', 'status'])`
- `unique(['egs_unit_id', 'type', 'environment', 'status'])` — **partial semantics enforced in application code**: at most one `active` row per (unit, type, environment). MySQL cannot express a partial unique index; enforce with a transactional guard in `CertificateService` and a nightly integrity check.
- `index('not_after')` — renewal sweeps.

---

### 4.6 `invoices`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | `bigIncrements` | no | | |
| `organization_id` | `foreignId` → `organizations.id` | no | | cascade; tenant key |
| `tax_registration_id` | `foreignId` → `tax_registrations.id` | no | | restrict |
| `egs_unit_id` | `foreignId` → `zatca_egs_units.id` | yes | `null` | Null for non-KSA invoices |
| `store_id` | `foreignId` → `stores.id` | yes | `null` | `nullOnDelete` — invoices outlive stores |
| `order_id` | `foreignId` → `orders.id` | yes | `null` | `nullOnDelete` |
| `parent_invoice_id` | `foreignId` → `invoices.id` | yes | `null` | Set on credit/debit notes |
| `invoice_number` | `string(64)` | no | | Human-readable, sequential per org (BT-1) |
| `uuid` | `uuid` | no | | KSA-1, UUID v4 |
| `document_type` | `enum('invoice','credit_note','debit_note','prepayment')` | no | `'invoice'` | |
| `type_code` | `string(3)` | no | | `388` / `381` / `383` / `386` |
| `subtype` | `enum('standard','simplified')` | no | | Drives clearance vs reporting |
| `transaction_code` | `char(7)` | no | | `cbc:InvoiceTypeCode/@name` |
| `country_code` | `char(2)` | no | | `SA`, `AE`, … |
| `icv` | `unsignedBigInteger` | yes | `null` | KSA-16; null for non-KSA |
| `pih` | `string(88)` | yes | `null` | KSA-13, base64 SHA-256 of previous doc |
| `invoice_hash` | `string(88)` | yes | `null` | Base64 SHA-256 of this doc (becomes the next PIH) |
| `issue_date` | `date` | no | | BT-2 |
| `issue_time` | `time` | no | | |
| `issued_at` | `timestampTz` | no | | Absolute instant, used for QR tag 3 and the 24-hour clock |
| `supply_date` | `date` | yes | `null` | KSA-5; required for standard tax invoices |
| `currency_code` | `char(3)` | no | `'SAR'` | `cbc:DocumentCurrencyCode` |
| `tax_currency_code` | `char(3)` | no | `'SAR'` | Must be SAR for KSA |
| `exchange_rate` | `decimal(15,6)` | yes | `null` | Required when `currency_code != tax_currency_code` |
| `buyer_name` | `string(255)` | yes | `null` | Required for standard |
| `buyer_name_ar` | `string(255)` | yes | `null` | |
| `buyer_vat_number` | `string(20)` | yes | `null` | Presence is the primary standard/simplified signal |
| `buyer_identification_scheme` | `string(10)` | yes | `null` | CRN / NAT / IQA / PAS etc. |
| `buyer_identification_value` | `string(50)` | yes | `null` | |
| `buyer_street` | `string(255)` | yes | `null` | Required for standard (BR-KSA-10..14) |
| `buyer_building_number` | `string(10)` | yes | `null` | |
| `buyer_city` | `string(255)` | yes | `null` | |
| `buyer_postal_zone` | `string(10)` | yes | `null` | |
| `buyer_country_code` | `char(2)` | yes | `null` | |
| `line_extension_amount` | `decimal(15,2)` | no | `0` | BT-106, sum of line net |
| `allowance_total_amount` | `decimal(15,2)` | no | `0` | BT-107 |
| `charge_total_amount` | `decimal(15,2)` | no | `0` | BT-108 |
| `tax_exclusive_amount` | `decimal(15,2)` | no | `0` | BT-109 |
| `tax_amount` | `decimal(15,2)` | no | `0` | **BT-110** — QR tag 5 |
| `tax_inclusive_amount` | `decimal(15,2)` | no | `0` | BT-112 — QR tag 4 |
| `prepaid_amount` | `decimal(15,2)` | no | `0` | BT-113 |
| `payable_amount` | `decimal(15,2)` | no | `0` | BT-115 |
| `payment_means_code` | `string(3)` | yes | `null` | UN/ECE 4461 |
| `note_reason` | `string(1000)` | yes | `null` | **KSA-10 — mandatory on credit/debit notes** |
| `note_reason_ar` | `string(1000)` | yes | `null` | |
| `status` | `enum('draft','issued','submitting','cleared','reported','rejected','failed','superseded','void')` | no | `'draft'` | See §5.9 |
| `submission_deadline_at` | `timestampTz` | yes | `null` | `issued_at + 24h` for simplified |
| `xml_path` | `string(512)` | yes | `null` | Signed XML in object storage |
| `cleared_xml_path` | `string(512)` | yes | `null` | ZATCA-stamped XML returned by clearance |
| `pdf_path` | `string(512)` | yes | `null` | Bilingual PDF |
| `qr_base64` | `text` | yes | `null` | TLV → base64 |
| `certificate_id` | `foreignId` → `zatca_certificates.id` | yes | `null` | Which cert signed it |
| `issuer` | `enum('hubby','marketplace','external')` | no | `'hubby'` | See §7 |
| `external_issuer_reference` | `string(255)` | yes | `null` | Marketplace's invoice ID when `issuer != 'hubby'` |
| `created_by` | `foreignId` → `users.id` | yes | `null` | `nullOnDelete`; audit |
| `created_at` / `updated_at` | `timestamps` | | | |

**Indexes / constraints**
- `unique(['organization_id', 'invoice_number'])`
- `unique('uuid')`
- `unique(['egs_unit_id', 'icv'])` — enforces a gapless, non-reused counter per chain
- `unique(['organization_id', 'order_id', 'document_type'])` — **the primary anti-double-invoicing guard** (see §7). Nullable `order_id` means MySQL permits multiple NULLs, which is correct for manual invoices.
- `index(['organization_id', 'status'])`
- `index(['organization_id', 'issue_date'])`
- `index(['status', 'submission_deadline_at'])` — the retry sweeper's driving index
- `index('parent_invoice_id')`

> **No hard deletes, ever.** No `softDeletes` either — §3.9 prohibits deletion, and a `deleted_at` column invites a future developer to use it. Cancellation is `status = 'void'` **plus** a credit note.

---

### 4.7 `invoice_lines`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | `bigIncrements` | no | | |
| `invoice_id` | `foreignId` → `invoices.id` | no | | cascade |
| `organization_id` | `foreignId` → `organizations.id` | no | | cascade; denormalised tenant key |
| `line_number` | `unsignedInteger` | no | | 1-based, `cbc:ID` |
| `order_item_id` | `foreignId` → `order_items.id` | yes | `null` | `nullOnDelete` |
| `product_variant_id` | `foreignId` → `product_variants.id` | yes | `null` | `nullOnDelete` |
| `name` | `string(255)` | no | | BT-153 |
| `name_ar` | `string(255)` | yes | `null` | |
| `sku` | `string(100)` | yes | `null` | BT-155 |
| `quantity` | `decimal(15,4)` | no | | BT-129 |
| `unit_code` | `string(10)` | no | `'PCE'` | UN/ECE Rec 20 |
| `unit_price` | `decimal(15,4)` | no | | BT-146, **tax-exclusive** |
| `line_extension_amount` | `decimal(15,2)` | no | | BT-131, net of allowances |
| `allowance_amount` | `decimal(15,2)` | no | `0` | Line-level discount |
| `allowance_reason` | `string(255)` | yes | `null` | |
| `tax_category` | `char(1)` | no | `'S'` | UN/CEFACT 5305 subset: `S`,`Z`,`E`,`O` |
| `tax_percent` | `decimal(5,2)` | no | `15.00` | |
| `tax_amount` | `decimal(15,2)` | no | | KSA-11 |
| `line_amount_with_tax` | `decimal(15,2)` | no | | KSA-12 |
| `tax_exemption_reason_code` | `string(20)` | yes | `null` | Required when category ≠ `S` |
| `tax_exemption_reason` | `string(1000)` | yes | `null` | |
| `created_at` / `updated_at` | `timestamps` | | | |

**Indexes / constraints**
- `unique(['invoice_id', 'line_number'])`
- `index('organization_id')`
- `index('order_item_id')`

---

### 4.8 `invoice_submissions`

One row **per attempt**. Never updated in place after completion — this is the audit trail proving we tried (§3.11).

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | `bigIncrements` | no | | |
| `invoice_id` | `foreignId` → `invoices.id` | no | | cascade |
| `organization_id` | `foreignId` → `organizations.id` | no | | cascade |
| `attempt` | `unsignedInteger` | no | `1` | 1-based |
| `api` | `enum('compliance','clearance','reporting')` | no | | Which endpoint |
| `environment` | `enum('sandbox','simulation','production')` | no | | |
| `endpoint_url` | `string(512)` | no | | Exact URL called |
| `request_payload` | `json` | yes | `null` | **`invoice` base64 replaced by a pointer** — do not duplicate the XML |
| `request_hash` | `string(88)` | yes | `null` | `invoiceHash` sent |
| `http_status` | `unsignedSmallInteger` | yes | `null` | Null on transport failure |
| `zatca_status` | `string(32)` | yes | `null` | `CLEARED`, `NOT_CLEARED`, `REPORTED`, `NOT_REPORTED`, … |
| `response_body` | `json` | yes | `null` | Full ZATCA response, **cleared XML stripped to a path** |
| `warnings` | `json` | yes | `null` | `validationResults.warningMessages` |
| `errors` | `json` | yes | `null` | `validationResults.errorMessages` |
| `error_summary` | `string(1000)` | yes | `null` | First error, for list display |
| `outcome` | `enum('success','warning','rejected','transport_error','auth_error','timeout')` | no | | Our classification |
| `duration_ms` | `unsignedInteger` | yes | `null` | |
| `correlation_id` | `uuid` | no | | Ties log lines, job and submission together |
| `submitted_at` | `timestampTz` | no | | |
| `created_at` / `updated_at` | `timestamps` | | | |

**Indexes / constraints**
- `unique(['invoice_id', 'attempt'])`
- `index(['organization_id', 'outcome'])`
- `index(['invoice_id', 'submitted_at'])`
- `index('correlation_id')`

---

### 4.9 `stores` additions

`2026_07_22_000007_add_invoicing_columns_to_stores_table.php`:

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `invoice_issuer` | `enum('hubby','marketplace','external','none')` | no | `'hubby'` | Who issues invoices for orders from this store — see §7 |
| `invoice_issuer_locked` | `boolean` | no | `false` | `true` when the platform's policy is not merchant-configurable (e.g. Trendyol) |
| `tax_registration_id` | `foreignId` → `tax_registrations.id` nullable | yes | `null` | Which registration this store sells under |

Defaults per platform are seeded by the migration — see the table in §7.

---

### 4.10 Secret and certificate storage

Three secrets exist: the **EGS private key**, the **ZATCA API secret**, and (transiently) the **OTP**.

**Requirements**

1. **Never in plaintext at rest.** `private_key_encrypted` and `api_secret_encrypted` are written and read exclusively through Laravel's `encrypted` cast, which uses `APP_KEY` with AES-256-GCM.

   ```php
   // app/Models/ZatcaCertificate.php
   protected function casts(): array
   {
       return [
           'private_key_encrypted' => 'encrypted',
           'api_secret_encrypted'  => 'encrypted',
           'not_before'            => 'datetime',
           'not_after'             => 'datetime',
       ];
   }
   ```

2. **Hidden from serialisation.** `protected $hidden = ['private_key_encrypted', 'api_secret_encrypted', 'csr_pem'];` and no API resource ever exposes them. A `ZatcaCertificateResource` returns only `subject_dn`, `not_after`, `status`, `type`.

3. **`APP_KEY` is not sufficient alone for production.** `APP_KEY` lives in `.env` on the same host as the database. **Recommendation: envelope encryption** — a per-organization data key wrapped by a KMS master key (AWS KMS / a cloud HSM), with only the wrapped key in the database. Ship v1 on `APP_KEY` for sandbox/simulation, and **require KMS before any production CSID is issued**. Tracked in §13 Milestone 4.

4. **No logging.** Add `private_key_encrypted`, `api_secret_encrypted`, `csr`, `otp`, `binarySecurityToken`, `secret` to Laravel's log-scrubbing and to `config('logging')` processors. `invoice_submissions.request_payload` must have the `Authorization` header and `secret` removed before persisting.

5. **Non-exportability.** ZATCA prefers non-exportable keys (§3.5). A pure-PHP software module cannot offer that. **Documented deviation:** we rely on the explicitly permitted software-module path with encryption at rest, restricted access, and audit logging. Record this in the compliance dossier.

6. **Existing gap to fix.** `integrations.access_token` / `refresh_token` are currently stored **in plaintext** (no `encrypted` cast on `App\Models\Integration`). That is a pre-existing issue outside this spec's scope, but the same review that lands certificate encryption should fix it — a compliance audit that finds plaintext OAuth tokens will not look kindly on the invoicing module either.

7. **Object storage.** `xml_path`, `cleared_xml_path`, `pdf_path` point at a private, versioned, **object-lock / WORM-enabled** bucket. Objects are keyed `orgs/{organization_id}/invoices/{yyyy}/{mm}/{uuid}.xml`. No public URLs; the API streams them behind authorization.

---

## 5. Domain logic

### 5.1 Invoice numbering (BT-1)

- Format: `{prefix}-{YYYY}-{seq}`, e.g. `INV-2026-000001`. Configurable prefix per organization; credit notes use `CN-`, debit notes `DN-`.
- **Sequential, unique, gap-free per organization** (a UAE requirement, §3.17, and good practice for KSA).
- Allocated in the same transaction as the ICV, from a `SELECT … FOR UPDATE` on a counter row.
- `invoice_number` is **not** the ICV and **not** the UUID. Three distinct identifiers, three distinct purposes.

### 5.2 UUID (KSA-1)

UUID v4, generated per document, `unique` across the table. **Never reused after a rejection** (§3.10).

### 5.3 ICV and PIH — atomic allocation

The ICV is a monotonically increasing integer **per EGS unit**, and the PIH is the hash of the immediately preceding document **in that same unit**. Both must be allocated atomically or the chain corrupts under concurrency — and a corrupt chain means every subsequent invoice is rejected.

```php
// app/Services/Zatca/ChainAllocator.php
public function allocate(ZatcaEgsUnit $unit): ChainSlot
{
    return DB::transaction(function () use ($unit) {
        $locked = ZatcaEgsUnit::whereKey($unit->id)->lockForUpdate()->firstOrFail();

        if ($locked->chain_broken_at !== null) {
            throw new ChainBrokenException($locked->id);
        }

        $icv = $locked->icv_counter + 1;
        $pih = $locked->last_invoice_hash ?? self::GENESIS_PIH;

        $locked->update(['icv_counter' => $icv]);

        return new ChainSlot(icv: $icv, pih: $pih);
    });
}
```

- The transaction spans allocation **and** insertion of the `invoices` row, so a rolled-back invoice does not burn an ICV.
- After signing, `commit(ChainSlot, invoiceHash)` writes `last_invoice_hash` and `last_invoice_id` under the same lock discipline.
- `GENESIS_PIH` — **[UNVERIFIED]**, see §3.6. Defined as a single constant so one edit fixes it everywhere.
- **Rejected documents keep their ICV consumed.** ZATCA says a rejected document's ICV must not be reused (§3.10), and the next document chains off the *immediately preceding document*, which per §3.10 is the last document we generated — including the rejected one. **[UNVERIFIED — the highest-consequence ambiguity in this spec.]** ZATCA's FAQ says the PIH is "based on immediately preceding document (not necessarily linked to the rejected invoice)", which can be read either as "the rejected invoice still forms the chain link" or "skip the rejected one". **Both readings must be tested against the simulation environment in Milestone 2 and the answer recorded here before production.** The implementation isolates this in one method (`ChainAllocator::commit`) so the decision is a one-line change.

### 5.4 Standard vs simplified determination

```
if country != SA                      -> non-fiscal invoice, no ZATCA
else if buyer_vat_number is present and valid (15 digits, 3…3)
                                      -> standard  (388 / name 0100000) -> CLEARANCE
else                                  -> simplified (388 / name 0200000) -> REPORTING
```

- The merchant may override to standard on a per-invoice basis (a B2B buyer who supplied a VAT number out-of-band), but **not** the reverse: if a VAT number is present, it must be a standard invoice.
- Standard invoices additionally require buyer street, city, country (BR-KSA-10..14) and `supply_date` (KSA-5). Validation refuses to issue a standard invoice missing these, with a field-level error surfaced in the UI.
- The EGS unit's `invoice_type_map` (`TSCZ`) must permit the subtype being issued — a unit onboarded as `0100` cannot issue simplified invoices.

### 5.5 QR TLV construction

```php
// app/Services/Zatca/QrCodeBuilder.php
public function build(Invoice $invoice, string $signature, string $publicKey, ?string $certSignature): string
{
    $tlv  = $this->tag(1, $invoice->taxRegistration->legal_name_ar ?? $invoice->taxRegistration->legal_name);
    $tlv .= $this->tag(2, $invoice->taxRegistration->vat_number);
    $tlv .= $this->tag(3, $invoice->issued_at->utc()->format('Y-m-d\TH:i:s\Z'));
    $tlv .= $this->tag(4, number_format((float) $invoice->tax_inclusive_amount, 2, '.', ''));
    $tlv .= $this->tag(5, number_format((float) $invoice->tax_amount, 2, '.', ''));
    $tlv .= $this->tagRaw(6, base64_decode($invoice->invoice_hash)); // 32 raw bytes
    $tlv .= $this->tagRaw(7, $signature);
    $tlv .= $this->tagRaw(8, $publicKey);

    if ($invoice->subtype === 'simplified') {
        $tlv .= $this->tagRaw(9, $certSignature);
    }

    return base64_encode($tlv);
}

private function tag(int $tag, string $value): string
{
    return $this->tagRaw($tag, mb_convert_encoding($value, 'UTF-8'));
}

private function tagRaw(int $tag, string $bytes): string
{
    if (strlen($bytes) > 255) {
        throw new QrFieldTooLongException($tag, strlen($bytes));
    }
    return chr($tag) . chr(strlen($bytes)) . $bytes;
}
```

- Length is the **byte** length of the UTF-8 encoding, not the character count — critical for Arabic seller names, where one character is 2 bytes.
- Result must be **≤ 700 characters** after base64 (§3.7); assert and fail loudly if exceeded.
- Tag 9 is present **only** for simplified invoices and their notes.
- Tags 6–8 carry **raw bytes**, not base64 strings — a very common implementation bug.

### 5.6 Signing pipeline

```
1. Build UBL 2.1 XML (no UBLExtensions, no QR node, no cac:Signature)
2. Clone; remove ext:UBLExtensions, AdditionalDocumentReference[ID='QR'], cac:Signature   (BR-KSA-27)
3. Canonicalise (XML C14N)                    -> canonical bytes
4. SHA-256(canonical bytes)                   -> invoice_hash (raw 32 bytes; base64 for storage)
5. ECDSA-sign the digest with the EGS private key  -> signature
6. Build QR TLV from hash + signature + public key (+ ZATCA cert signature for simplified)
7. Inject QR into AdditionalDocumentReference[ID='QR']
8. Build XAdES B-B UBLExtensions (SignedInfo, SignatureValue, KeyInfo w/ full chain,
   SignedSignatureProperties incl. SigningTime and SigningCertificate digest)
9. Emit final signed XML -> store -> submit
```

Steps 2–4 are shared verbatim with PIH computation (§3.6), so they live in one class, `InvoiceHasher`, used by both.

### 5.7 VAT calculation

Price decomposition (VAT-inclusive vs exclusive) is owned by the **Profit & Cost Engine** spec; this module consumes `prices_include_tax` and the per-line net/tax split it produces. Here we only define the invoice-side arithmetic.

**Given VAT-inclusive line prices** (the MENA default):

```
line_net   = round(gross / (1 + rate/100), 4)      # working precision
line_vat   = gross - line_net                       # derive, never round twice
```

**Given VAT-exclusive prices:**

```
line_vat   = line_net * rate/100
```

**Document totals** — computed at document level per §3.8, **not** as a sum of rounded line values:

```
line_extension_amount = round(Σ line_net, 2)
tax_exclusive_amount  = round(line_extension_amount - allowance_total + charge_total, 2)
tax_amount            = round(tax_exclusive_amount_per_category * rate/100, 2)   # per VAT category, then summed
tax_inclusive_amount  = round(tax_exclusive_amount + tax_amount, 2)
payable_amount        = round(tax_inclusive_amount - prepaid_amount, 2)
```

- **Half-up** rounding throughout. PHP's default `round()` is half-up for positive values; use `BCMath` (`bcadd`, `bcmul`, `bcdiv`) or a `Money` value object to avoid float drift on 15-digit decimals. **Do not use raw floats for money.**
- `tax_amount` is grouped by **(tax_category, tax_percent)** into `cac:TaxSubtotal` blocks; each is rounded at that group level, and BT-110 is the sum of the rounded group amounts. This matches *"rounded on document level and not as a summation of rounded Invoice line VAT amounts."*
- A **rounding-difference guard**: if `|Σ line_amount_with_tax − tax_inclusive_amount| > 0.02`, refuse to issue and raise a validation error. Silent rounding drift is the most common cause of ZATCA rejection.

**Multi-currency:** KSA invoices must present amounts in **SAR** as the tax currency. If the order currency is not SAR, set `currency_code` to the order currency, `tax_currency_code = 'SAR'`, and populate `exchange_rate` and `cbc:TaxCurrencyCode` with the converted `cac:TaxTotal`. **[UNVERIFIED]** the exact required exchange-rate source and rounding for the SAR conversion — confirm with a tax advisor.

### 5.8 Credit and debit notes

- Cancelling an issued invoice is **only** possible by issuing a credit note (§3.9). The UI must never offer "delete invoice".
- A credit note copies the parent's buyer details and subtype, sets `type_code = 381`, `parent_invoice_id`, and **must** carry `note_reason` (KSA-10, BR-KSA rule for type 381/383).
- It references the parent via `cac:BillingReference/cac:InvoiceDocumentReference/cbc:ID` = parent `invoice_number`.
- It gets its **own** UUID, ICV and PIH, and goes through the same clearance/reporting flow as its parent's subtype.
- **Full cancellation** = credit note for the full amount, then `parent.status = 'void'`. **Partial refund** = credit note for the refunded lines only; parent stays `cleared`/`reported`.
- Debit notes (383) increase the amount owed; same mechanics, `note_reason` also mandatory.
- **A credit note may be issued for an invoice that was never successfully cleared** — this is the prescribed remedy for `NOT_REPORTED` (§3.10).

### 5.9 Invoice status machine

```
draft ──issue()──> issued ──dispatch──> submitting
                                          │
     ┌────────────────────────────────────┼────────────────────────────┐
     ▼                                    ▼                            ▼
  cleared (standard, CLEARED)      reported (simplified,        rejected (NOT_CLEARED /
  or with warnings                  REPORTED)                    NOT_REPORTED)
     │                                    │                            │
     └──────── credit note ───────────────┴────────> superseded / void ┘

  submitting ──retries exhausted / >24h──> failed  (stays retryable; alerts raised)
```

- `issued` → the document exists, is signed, is legally an invoice, and has been given to the customer. **Irreversible.**
- `failed` is *not* terminal — the sweeper keeps retrying (§3.11 requires continuous attempts).
- `rejected` is terminal for that document; remediation is a new document.
- Only `draft` may be edited or deleted. `issued` and beyond are immutable; any write attempt throws.

---

## 6. Backend

### 6.1 Models

`app/Models/`

| Model | Notes |
|---|---|
| `TaxRegistration` | `belongsTo(Organization)`, `hasMany(ZatcaEgsUnit)`, `hasMany(Invoice)` |
| `ZatcaEgsUnit` | `belongsTo(Organization, TaxRegistration, Store)`, `hasMany(ZatcaCertificate)`, `hasMany(Invoice)`; `activeCertificate()` helper |
| `ZatcaCertificate` | encrypted casts + `$hidden` per §4.10 |
| `Invoice` | `belongsTo(Organization, TaxRegistration, ZatcaEgsUnit, Store, Order)`, `hasMany(InvoiceLine)`, `hasMany(InvoiceSubmission)`, `belongsTo(Invoice, 'parent_invoice_id')`, `hasMany(Invoice, 'parent_invoice_id')` as `notes` |
| `InvoiceLine` | |
| `InvoiceSubmission` | |

All six use a `BelongsToOrganization` global scope (see §10).

### 6.2 Services

`app/Services/Zatca/`

| Class | Responsibility |
|---|---|
| `InvoiceBuilder` | Order → `Invoice` + `InvoiceLine[]`, incl. subtype determination and VAT arithmetic (§5.4, §5.7) |
| `UblRenderer` | `Invoice` → UBL 2.1 XML DOM |
| `InvoiceHasher` | BR-KSA-27 transform → C14N → SHA-256. Shared by stamp and PIH (§5.6) |
| `CryptographicStamper` | ECDSA signing, XAdES B-B `UBLExtensions` construction |
| `QrCodeBuilder` | TLV → base64 (§5.5) |
| `ChainAllocator` | Atomic ICV + PIH allocation and commit (§5.3) |
| `CsrGenerator` | PKCS#10 CSR with the OIDs in §3.15 |
| `CertificateService` | CCSID → compliance checks → PCSID; renewal; revocation |
| `ZatcaClient` | HTTP client: clearance, reporting, compliance. Timeouts, retries, circuit breaker |
| `InvoicePdfRenderer` | Bilingual PDF with embedded QR |
| `ChannelInvoicePolicy` | Per-platform issuer arbitration (§7) |
| `TaxCalculator` | Thin adapter over the Profit & Cost Engine's VAT primitives |

`app/Services/Tax/UaeInvoiceValidator` — FTA field completeness for AE invoices.

**`ZatcaClient` resilience contract:**

| Concern | Value |
|---|---|
| Connect timeout | 10 s |
| Request timeout | 60 s (clearance is synchronous and can be slow) |
| Transport retries (in-request) | 2, exponential backoff 1 s → 3 s, **only** on connect errors / 502 / 503 / 504 |
| Non-retryable | 400, 401, 403 (bad payload / bad credentials) — surfaced immediately |
| Circuit breaker | 10 consecutive transport failures across the org → open for 5 min; opens per `(environment, api)`, never per tenant, since the outage is ZATCA-wide |
| Idempotency | The `uuid` + `invoiceHash` pair is the idempotency key. On timeout, **never** regenerate the document — re-submit the identical payload |
| Correlation | `correlation_id` (UUID) on every log line and `invoice_submissions` row |

### 6.3 Jobs

`app/Jobs/` — matching the repo's existing job style (`SyncOrdersJob`, `PushInventoryJob`).

| Job | Queue | Trigger | Behaviour |
|---|---|---|---|
| `GenerateInvoiceJob` | `invoices` | `OrderPaid` event / manual | Builds and issues the invoice; idempotent on `(organization_id, order_id, document_type)` |
| `SubmitInvoiceJob` | `zatca` | After issue | Clearance or reporting per subtype. `tries = 8`, `backoff = [60, 300, 900, 1800, 3600, 7200, 14400, 21600]` (1 min → 6 h), `timeout = 120`. Writes one `invoice_submissions` row per attempt |
| `ResubmitPendingInvoicesJob` | `zatca` | Scheduler, **every 15 minutes** (matching ZATCA's guidance, §3.11) | Sweeps `status in (issued, submitting, failed)` older than the backoff window and re-dispatches. Bounded batch (200) to avoid thundering-herd on recovery |
| `RenewCertificatesJob` | `default` | Scheduler, daily 02:00 | Finds certificates with `not_after < now + 30 days`; notifies the org owner; attempts auto-renewal where possible (**note: renewal needs a fresh portal OTP, so it cannot be fully automatic — §3.14**) |
| `CheckReportingDeadlinesJob` | `default` | Scheduler, hourly | Simplified invoices past `submission_deadline_at` and not `reported` → high-severity notification instructing the merchant to file ZATCA's failure-notification form (§3.11) |
| `VerifyChainIntegrityJob` | `default` | Scheduler, daily 03:00 | Per EGS unit, walks the last N invoices verifying `invoice[n].pih == invoice[n-1].invoice_hash` and ICV contiguity; sets `chain_broken_at` and alerts on mismatch |
| `GenerateInvoicePdfJob` | `default` | After clearance/reporting | Renders the bilingual PDF (uses the cleared XML when available) |

**Queue isolation:** the `zatca` queue is separate so a ZATCA outage backing up submissions cannot starve order sync.

### 6.4 Events

`app/Events/`

| Event | Payload | Listeners |
|---|---|---|
| `InvoiceIssued` | `Invoice` | dispatch `SubmitInvoiceJob` |
| `InvoiceCleared` | `Invoice`, `InvoiceSubmission` | PDF render, notification, analytics |
| `InvoiceReported` | `Invoice`, `InvoiceSubmission` | PDF render, notification |
| `InvoiceRejected` | `Invoice`, `InvoiceSubmission` | High-severity notification, remediation task |
| `InvoiceSubmissionFailed` | `Invoice`, `InvoiceSubmission` | Retry accounting; alert after N |
| `ZatcaChainBroken` | `ZatcaEgsUnit` | **Blocks issuance**, pages the on-call, notifies org owners |
| `CertificateExpiringSoon` | `ZatcaCertificate`, `daysRemaining` | Email + in-app notification |
| `EgsUnitOnboarded` | `ZatcaEgsUnit` | Notification, unlock invoicing |

### 6.5 API endpoints

All under `Route::middleware(['auth:sanctum', 'org.member'])`, following the existing `routes/api.php` structure. Additional `can:` gates per §10.

#### Tax settings

| Method | Path | Auth | Body / Query | Response |
|---|---|---|---|---|
| `GET` | `/api/tax/registrations` | `org.member` | — | `{ data: TaxRegistration[] }` |
| `POST` | `/api/tax/registrations` | `owner`\|`admin` | see below | `201 { data: TaxRegistration }` |
| `PUT` | `/api/tax/registrations/{id}` | `owner`\|`admin` | partial | `200 { data }` |

`POST /api/tax/registrations` validation:

```php
'country_code'   => ['required', 'string', 'size:2', Rule::in(['SA','AE'])],
'legal_name'     => ['required', 'string', 'max:255'],
'legal_name_ar'  => ['required_if:country_code,SA', 'string', 'max:255'],
'vat_number'     => ['nullable', 'string', 'max:20',
                     Rule::when(fn ($i) => $i['country_code'] === 'SA', ['regex:/^3\d{13}3$/']),
                     Rule::when(fn ($i) => $i['country_code'] === 'AE', ['regex:/^\d{15}$/'])],
'is_vat_group'   => ['boolean'],
'group_member_tin' => ['required_if:is_vat_group,true', 'digits:10'],
'default_vat_rate' => ['required', 'numeric', 'between:0,100'],
'street_name'    => ['required_if:country_code,SA', 'string', 'max:255'],
'building_number'=> ['required_if:country_code,SA', 'string', 'max:10'],
'city_name'      => ['required_if:country_code,SA', 'string', 'max:255'],
'postal_zone'    => ['required_if:country_code,SA', 'digits:5'],
'einvoicing_mode'=> ['required', Rule::in(['off','phase1','phase2'])],
'environment'    => ['required', Rule::in(['sandbox','simulation','production'])],
```

Errors: `422` with Laravel's field-keyed bag. `403` if the actor is `viewer`.

#### EGS units and onboarding

| Method | Path | Auth | Body | Response |
|---|---|---|---|---|
| `GET` | `/api/tax/egs-units` | `org.member` | — | `{ data: EgsUnit[] }` (never includes key material) |
| `POST` | `/api/tax/egs-units` | `owner`\|`admin` | `tax_registration_id`, `name`, `branch_name?`, `registered_address`, `industry`, `invoice_type_map`, `store_id?` | `201` — generates the key pair and CSR; status → `csr_generated` |
| `POST` | `/api/tax/egs-units/{id}/compliance-csid` | `owner`\|`admin` | `{ otp: string }` | `200 { status, request_id }` — submits the CSR; status → `compliance_csid` |
| `POST` | `/api/tax/egs-units/{id}/compliance-checks` | `owner`\|`admin` | — | `202` — dispatches the six mandatory test documents (§12); status → `compliance_passed` on full pass |
| `POST` | `/api/tax/egs-units/{id}/production-csid` | `owner`\|`admin` | — | `200` — exchanges CCSID for PCSID; status → `active` |
| `POST` | `/api/tax/egs-units/{id}/renew` | `owner`\|`admin` | `{ otp: string }` | `200` |
| `DELETE` | `/api/tax/egs-units/{id}` | `owner` | — | `200` — marks revoked; **never deletes**; refused if the unit has issued invoices |

`otp` validation: `['required', 'digits:6']`. Rate limit: `throttle:5,1` on the OTP-bearing routes.

#### Invoices

| Method | Path | Auth | Notes |
|---|---|---|---|
| `GET` | `/api/invoices` | `org.member` | Filters: `status`, `subtype`, `document_type`, `store_id`, `country_code`, `date_from`, `date_to`, `q` (number/buyer). Paginated 25. Sort `issued_at desc` |
| `GET` | `/api/invoices/{id}` | `org.member` | Includes lines, latest submission, submission count |
| `POST` | `/api/invoices` | `owner`\|`admin` | Manual invoice, or `{ order_id }` to generate from an order. Returns `409` if one already exists for that order |
| `PUT` | `/api/invoices/{id}` | `owner`\|`admin` | **Only when `status = 'draft'`.** `422` otherwise |
| `POST` | `/api/invoices/{id}/issue` | `owner`\|`admin` | Allocates ICV/PIH, signs, stores, dispatches `SubmitInvoiceJob`. Irreversible |
| `POST` | `/api/invoices/{id}/submit` | `owner`\|`admin` | Manual re-submit. `throttle:10,1` |
| `POST` | `/api/invoices/{id}/credit-note` | `owner`\|`admin` | `{ reason, reason_ar, lines?: [{invoice_line_id, quantity}] }`. Omitting `lines` credits in full |
| `POST` | `/api/invoices/{id}/debit-note` | `owner`\|`admin` | `{ reason, reason_ar, lines: [...] }` |
| `GET` | `/api/invoices/{id}/xml` | `org.member` | Streams signed XML (or cleared XML if present). `Content-Type: application/xml` |
| `GET` | `/api/invoices/{id}/pdf` | `org.member` | Streams bilingual PDF. `?lang=ar\|en` controls the primary column |
| `GET` | `/api/invoices/{id}/submissions` | `org.member` | Attempt history with errors/warnings |
| `GET` | `/api/invoices/export` | `org.member` | CSV, mirroring `/api/orders/export` |
| `DELETE` | `/api/invoices/{id}` | `owner` | **Only `draft`.** `422` with an explanatory message otherwise — never silently succeed |

`POST /api/invoices/{id}/credit-note` validation:

```php
'reason'    => ['required', 'string', 'max:1000'],
'reason_ar' => ['required', 'string', 'max:1000'],   // KSA invoices
'lines'                  => ['sometimes', 'array', 'min:1'],
'lines.*.invoice_line_id'=> ['required', 'integer', Rule::exists('invoice_lines', 'id')
                              ->where('invoice_id', $invoice->id)],
'lines.*.quantity'       => ['required', 'numeric', 'gt:0'],
```

Responses: `201 { data: Invoice }`. `422` if the parent is not `cleared`/`reported`/`rejected`, or if the cumulative credited quantity would exceed the parent's.

**Standard error envelope** (matching the repo's existing `response()->json(['message' => ...], code)`):

```json
{ "message": "Invoice cannot be edited once issued.", "code": "INVOICE_IMMUTABLE" }
```

---

## 7. Per-channel invoicing responsibility

The single most dangerous failure in this feature is **double invoicing**: the merchant and the marketplace both issue a tax invoice for the same sale, producing two ZATCA-cleared documents for one transaction and overstating output VAT.

### 7.1 The rule

**Whoever is the "seller of record" (supplier) for VAT purposes must issue the tax invoice.** For third-party marketplace sales, that is normally the **merchant**, not the marketplace — the marketplace is an agent. However, several marketplaces offer to issue and/or submit invoices **on the seller's behalf**, and where the merchant has opted in, Hubby must not also issue.

### 7.2 Per-platform policy

| Platform | Seller of record | Who issues | Default `invoice_issuer` | Hubby behaviour |
|---|---|---|---|---|
| **Shopify** | Merchant | **Hubby** | `hubby` | Merchant's own storefront; no third party issues. Full ZATCA generation. **[VERIFIED by reasoning — Shopify is a storefront, not a marketplace/agent]** |
| **WooCommerce** | Merchant | **Hubby** | `hubby` | Same as Shopify. Watch for merchant-installed ZATCA WooCommerce plugins — warn on connect |
| **Salla** | Merchant | Configurable | `hubby` | Salla is a KSA SaaS storefront with its own e-invoicing capability and app ecosystem. **High double-invoicing risk.** Connect wizard asks explicitly; if Salla e-invoicing is on, set `external`. **[UNVERIFIED — confirm Salla's current ZATCA behaviour and whether it can be disabled per-store]** |
| **Zid** | Merchant | Configurable | `hubby` | Same reasoning and same risk as Salla. **[UNVERIFIED — confirm Zid's current ZATCA behaviour]** |
| **Amazon (amazon.sa)** | Merchant (3P) | Configurable | `marketplace` | Amazon's **VAT Calculation Service (VCS)** issues invoices on the seller's behalf when enabled. Default to `marketplace` (safe: we under-issue rather than double-issue) and let the merchant switch to `hubby` if VCS is off. **[UNVERIFIED — whether Amazon.sa VCS performs ZATCA Phase-2 clearance/reporting, or only produces a VAT invoice document. Confirm with Amazon Seller Central before enabling either default]** |
| **Noon** | Merchant | Configurable, **opt-in at Noon** | `marketplace` | Noon offers KSA sellers an opt-in ZATCA integration under which **Noon submits invoices to ZATCA on the seller's behalf**; sellers who do **not** opt in remain liable to submit their own. Merchant must tell us which. **[VERIFIED — secondary source: [Noon Partners KB — ZATCA Integration for KSA E-Invoicing](https://support.noon.partners/portal/en/kb/articles/zatca-integration-for-ksa-e-invoicing). Confirm directly with Noon partner support before go-live]** |
| **Trendyol** | Merchant | **Neither (not ZATCA)** | `none`, `locked = true` | Turkish marketplace under Turkey's **e-Fatura / e-Arşiv** regime, administered by the GİB — a completely separate mandate with its own formats and providers. Trendyol orders are **never** routed to ZATCA. Turkish e-invoicing is out of scope (§2) |

> **Design bias:** where responsibility is ambiguous, **default to "the marketplace issues"**. Failing to issue an invoice we should have issued is a fixable back-dated correction; issuing a duplicate cleared invoice requires a credit note, a corrected invoice, and an explanation to ZATCA.

### 7.3 Enforcement

1. **Store-level policy.** `stores.invoice_issuer` (§4.9) is set at connect time by an explicit wizard question and is editable in tax settings. `invoice_issuer_locked` prevents changing platform-mandated values.
2. **Hard uniqueness.** `unique(['organization_id', 'order_id', 'document_type'])` on `invoices` makes a duplicate physically impossible for the same order, whatever the code does.
3. **Idempotent generation.** `GenerateInvoiceJob` uses `firstOrCreate` on that key inside a transaction; a re-delivered webhook cannot produce a second document.
4. **Policy gate.** `ChannelInvoicePolicy::shouldIssue(Order $order): bool` returns `false` unless `store.invoice_issuer === 'hubby'` **and** the order's tax registration is `SA` with `einvoicing_mode = 'phase2'`. `GenerateInvoiceJob` exits early otherwise, recording a `sync_logs` entry so the merchant can see *why* no invoice was created.
5. **Marketplace-issued visibility.** When `issuer = 'marketplace'`, we still create an `invoices` row with `status = 'issued'`, `issuer = 'marketplace'` and `external_issuer_reference`, but **never** submit it. This keeps VAT reporting complete without touching ZATCA.
6. **Duplicate detector.** A daily job flags orders that have both a Hubby-issued invoice and a marketplace invoice reference in `orders.raw_data`, surfacing them in the dashboard as a compliance warning.

---

## 8. Dashboard (Next.js 16 App Router, Tailwind v4)

### 8.1 Routes

```
/dashboard/invoices                 list
/dashboard/invoices/[id]            detail
/dashboard/invoices/new             manual invoice
/dashboard/settings/tax             registrations, VAT, addresses
/dashboard/settings/tax/onboarding  ZATCA onboarding wizard
/dashboard/settings/tax/egs-units   unit list, certificate status
```

### 8.2 Invoice list

- Columns: number, date, buyer, type badge (standard/simplified, credit/debit), amount (`<Money>` — SAR renders the official riyal glyph via `Money.tsx`), **status pill**, channel.
- Status pills: `draft` (grey), `issued` (blue), `submitting` (blue, animated), `cleared` (green + ZATCA check), `reported` (green), `rejected` (red), `failed` (amber), `void` (grey strikethrough).
- Filters mirroring `/dashboard/orders`: status, type, store, date range, search.
- **Compliance banner** above the table when any of: unsubmitted simplified invoices approaching the 24-hour deadline; a broken chain; a certificate expiring within 30 days; a rejected invoice awaiting remediation.
- CSV export reusing the orders export pattern.

### 8.3 Invoice detail

- Header: number, UUID, ICV, status, issue/supply dates, ZATCA environment badge (loud amber for sandbox/simulation so nobody mistakes a test invoice for a real one).
- Parties panel (seller / buyer) with VAT numbers.
- Lines table with per-line net, VAT category, VAT amount, gross.
- Totals block: net, discounts, VAT per category, gross, payable.
- **QR panel** rendering the stored `qr_base64` as an actual scannable QR, with a "verify in the ZATCA app" hint.
- **Submission timeline** — one entry per `invoice_submissions` row: attempt number, timestamp, endpoint, HTTP status, ZATCA status, duration, and expandable errors/warnings.
- Actions: Download PDF, Download XML, Download cleared XML, Resubmit, Issue credit note, Issue debit note. Actions are gated by status and role and show a disabled-reason tooltip rather than vanishing.

### 8.4 Error remediation

Rejections must be actionable, not a JSON dump. `ZatcaErrorCatalog` maps ZATCA error codes to a localized cause + fix + deep link:

| Category | Message shown | Action |
|---|---|---|
| Missing buyer address on a standard invoice | "This B2B invoice needs the buyer's street, city and country." | Inline edit → new corrected invoice |
| VAT number format | "The buyer's VAT number must be 15 digits starting and ending with 3." | Inline edit |
| Chain / PIH mismatch | "Invoice sequence integrity check failed. New invoices are paused." | Contact support; chain repair runbook |
| Certificate expired | "Your ZATCA certificate expired on {date}." | → onboarding wizard, renew step |
| Rounding mismatch | "Line totals don't match the invoice total." | Support escalation (indicates a bug) |
| Unknown code | Raw code + message + "Contact support" | Copy diagnostics button |

Unmapped codes must still render the raw ZATCA text — never swallow an error we don't recognise.

### 8.5 Onboarding wizard

Six steps, resumable, with state on `zatca_egs_units.status`:

1. **Tax registration** — legal name (EN + AR), VAT number, address. Live format validation.
2. **Solution unit** — name, branch, address/URL, invoice types (`TSCZ`).
3. **Generate CSR** — one click; shows the CSR for download (some accountants want it). Explains that the private key never leaves Hubby.
4. **OTP** — explicit instructions to obtain a 6-digit OTP from the Fatoora portal, with a **1-hour validity countdown** (§3.14) and an external link.
5. **Compliance checks** — runs the mandatory test documents with per-document pass/fail.
6. **Go live** — exchanges for the Production CSID; shows certificate expiry and a renewal reminder.

Environment selector (sandbox / simulation / production) is prominent, with a confirmation dialog before production.

### 8.6 i18n

New dict `frontend/src/i18n/dicts/invoices.ts`, following the existing `{ en: {...}, ar: {...} }` shape used by `orders.ts`. Additions to `settings.ts` for the tax section.

Key coverage:

```ts
export const invoices = {
  en: {
    title: 'Invoices',
    subtitle: 'ZATCA-compliant tax invoices for all your stores.',
    status: { draft: 'Draft', issued: 'Issued', submitting: 'Submitting…',
              cleared: 'Cleared', reported: 'Reported', rejected: 'Rejected',
              failed: 'Retrying', void: 'Cancelled' },
    type: { standard: 'Standard (B2B)', simplified: 'Simplified (B2C)',
            creditNote: 'Credit note', debitNote: 'Debit note' },
    zatca: { clearedBadge: 'Cleared by ZATCA', reportedBadge: 'Reported to ZATCA',
             environment: { sandbox: 'Sandbox', simulation: 'Simulation', production: 'Live' },
             deadlineWarning: 'Must be reported within {hours}h' },
    // …
  },
  ar: {
    title: 'الفواتير',
    subtitle: 'فواتير ضريبية متوافقة مع هيئة الزكاة والضريبة والجمارك لجميع متاجرك.',
    status: { draft: 'مسودة', issued: 'صادرة', submitting: 'جارٍ الإرسال…',
              cleared: 'مصادق عليها', reported: 'تم إبلاغها', rejected: 'مرفوضة',
              failed: 'إعادة المحاولة', void: 'ملغاة' },
    type: { standard: 'فاتورة ضريبية', simplified: 'فاتورة ضريبية مبسطة',
            creditNote: 'إشعار دائن', debitNote: 'إشعار مدين' },
    zatca: { clearedBadge: 'مصادق عليها من الهيئة', reportedBadge: 'تم إبلاغ الهيئة',
             environment: { sandbox: 'بيئة تجريبية', simulation: 'بيئة محاكاة', production: 'مباشر' },
             deadlineWarning: 'يجب الإبلاغ خلال {hours} ساعة' },
    // …
  },
};
```

**Arabic terminology must be the official ZATCA wording** — "فاتورة ضريبية" (tax invoice), "فاتورة ضريبية مبسطة" (simplified), "إشعار دائن" (credit note), "إشعار مدين" (debit note), "الرقم الضريبي" (VAT number). **Have a native Arabic-speaking Saudi accountant review the full dict before release** — merchants will judge credibility on this vocabulary.

RTL is already handled globally; verify that the QR panel, the LTR-only invoice number, and the submission timeline do not mirror incorrectly.

---

## 9. Mobile (Flutter)

Mobile is for **awareness and unblocking**, not authoring. Nobody onboards a certificate on a phone.

**In:**
- **Invoice list** — status, amount (existing riyal-glyph rendering), buyer, date; filter by status.
- **Invoice detail (read-only)** — totals, parties, status, QR rendered large enough to scan from the screen, submission timeline.
- **Share / download PDF** via the OS share sheet — genuinely useful when a buyer asks for an invoice.
- **Push notifications** — invoice rejected; simplified invoice nearing its 24-hour deadline; certificate expiring; chain broken. These are the events where hours matter.
- **One-tap resubmit** on a failed invoice.

**Out:**
- Onboarding wizard, CSR/OTP, certificate management, tax-settings editing, manual invoice creation, credit-note issuance (legally consequential — desktop, with confirmation).

Notifications reuse the existing `notifications` table and the user notification-preference plumbing, adding a `compliance` category that is **on by default and cannot be fully disabled** for `owner`/`admin` (a merchant must not be able to silence a legal deadline).

---

## 10. Permissions & multi-tenancy

### 10.1 Tenant isolation

- Every table in §4 carries `organization_id` **directly**. Invoices must never rely on `store_id → organization_id` for isolation, both because stores can be deleted and because a legal record needs an unambiguous owner.
- A `BelongsToOrganization` trait applies a global scope resolving the active organization from the request, matching the existing `EnsureOrganizationMember` middleware.
- Every controller additionally scopes explicitly (`->where('organization_id', $org->id)`) rather than trusting the global scope alone — defence in depth for a legal record.
- Route-model binding is **not** used bare for invoices; bindings resolve through the org-scoped query so a cross-tenant ID returns `404`, not `403` (no existence leak).

### 10.2 Certificate isolation

This is the highest-value secret in the system: possession of an org's private key allows forging cryptographically valid tax invoices in that taxpayer's name.

1. **One key pair per EGS unit per organization.** Never a shared Hubby-wide certificate. Hubby is the *solution provider*; each *taxpayer* holds their own CSID. This is also what ZATCA's model requires — the CSR carries the taxpayer's own VAT number.
2. **Encrypted at rest**, `$hidden`, never serialized (§4.10).
3. **No API surface returns key material.** Not even to `owner`. There is no "export private key" endpoint. If a merchant leaves, they onboard a new EGS unit elsewhere; keys are not portable by design.
4. **Decryption is scoped and logged.** `CertificateService::withSigningKey(EgsUnit $unit, Closure $fn)` is the only decryption path; it asserts `$unit->organization_id === $context->organization_id`, writes an audit entry, and zeroes the plaintext after use.
5. **Object storage keys are namespaced by org** (`orgs/{id}/invoices/…`) with per-request signed access, never public URLs.
6. **`invoice_submissions.request_payload` scrubs `Authorization` and `secret`** before persisting.

### 10.3 Role matrix

Roles are `owner`, `admin`, `viewer` (per `OrganizationController::ROLES`).

| Action | owner | admin | viewer |
|---|---|---|---|
| View invoices, PDF, XML | ✅ | ✅ | ✅ |
| Create/edit draft invoice | ✅ | ✅ | ❌ |
| **Issue** invoice | ✅ | ✅ | ❌ |
| Resubmit | ✅ | ✅ | ❌ |
| Issue credit/debit note | ✅ | ✅ | ❌ |
| Edit tax registration | ✅ | ✅ | ❌ |
| Create EGS unit / generate CSR | ✅ | ✅ | ❌ |
| Enter OTP / obtain CSID | ✅ | ✅ | ❌ |
| **Switch to production environment** | ✅ | ❌ | ❌ |
| Revoke EGS unit | ✅ | ❌ | ❌ |

Enforced via policies (`InvoicePolicy`, `TaxRegistrationPolicy`, `ZatcaEgsUnitPolicy`) plus route-level `can:` middleware.

### 10.4 Audit logging

Per §3.9 an EGS must log all user activity around invoice generation, and logs must not be modifiable. Add an append-only `compliance_audit_logs` write (or reuse `sync_logs` with a `compliance` channel) for: invoice issued, submitted, credit note created, tax settings changed, EGS unit created/revoked, certificate issued/renewed, key decrypted. **No update or delete path exists in code.** **[UNVERIFIED]** whether ZATCA requires these logs to be retained for the same period as the invoices — assume yes.

---

## 11. Edge cases & failure modes

| # | Scenario | Behaviour |
|---|---|---|
| 1 | **ZATCA down — standard/clearance** | Retry ~5 min inline; then mark `failed`, allow the merchant to share the **uncleared** invoice (clearly labelled "pending clearance" on the PDF), keep records, and keep retrying every ~15 min. Once cleared, regenerate the PDF from the cleared XML and notify the merchant to re-share. §3.11 |
| 2 | **ZATCA down — simplified/reporting** | Invoice is already valid and given to the customer. Retry at intervals until success. At `issued_at + 20h` warn; at +24h raise a high-severity notification instructing the merchant to file ZATCA's failure form. §3.11 |
| 3 | **Clearance switched off by ZATCA** | The response directs submission via reporting. `ZatcaClient` detects this and re-routes to the reporting endpoint, recording the redirect on the submission row. §3.13 |
| 4 | **Invoice rejected (`NOT_CLEARED` / `NOT_REPORTED`)** | Terminal for that document. `status = 'rejected'`. **Do not reuse UUID or ICV.** Remediation: for a `NOT_REPORTED` simplified invoice already given to a customer, ZATCA prescribes issuing a **credit note** then a corrected invoice. The UI drives exactly this. §3.10 |
| 5 | **Warnings but cleared** | `status = 'cleared'`. Warnings are stored and shown, non-blocking, but counted on a dashboard so systematic warnings get fixed before they become errors. |
| 6 | **Chain break** (`invoice[n].pih != invoice[n-1].invoice_hash`, or an ICV gap) | `VerifyChainIntegrityJob` sets `chain_broken_at`, fires `ZatcaChainBroken`, **blocks all further issuance on that EGS unit**, and pages on-call. Never auto-repair — a wrong repair is worse than a pause. Runbook: identify the divergence point, confirm with ZATCA's records, decide between continuing the chain or onboarding a fresh EGS unit. **[UNVERIFIED]** whether ZATCA permits chain restart via a new EGS unit — confirm with ZATCA support. |
| 7 | **Concurrent invoice generation on one EGS unit** | Prevented by `SELECT … FOR UPDATE` on `zatca_egs_units` (§5.3). Throughput is bounded by the lock — acceptable, since correctness beats parallelism here. If a tenant outgrows it, add EGS units per store. |
| 8 | **Clock skew** | QR tag 3 and `SigningTime` come from the EGS clock (§3.5). Servers must run NTP with drift alerting. Additionally, refuse to issue if the app clock differs from the last ZATCA response `Date` header by > 5 minutes, and alert. A skewed clock produces silently invalid invoices. |
| 9 | **Timeout with unknown outcome** | ZATCA may have processed a request we never saw the response to. **Never regenerate the document.** Re-submit the identical `uuid` + `invoiceHash` + XML; ZATCA's duplicate handling then makes the operation idempotent. **[UNVERIFIED]** exact duplicate-submission semantics — confirm in simulation; if duplicates error, treat "already exists" as success. |
| 10 | **Certificate expired mid-operation** | `SubmitInvoiceJob` pre-checks `not_after`; if expired, fail fast with `CERTIFICATE_EXPIRED`, block issuance, and notify. Invoices already issued are unaffected — they remain valid, signed by the then-valid certificate. |
| 11 | **Certificate renewal** | New certificate row; old → `superseded`. Historical invoices retain `certificate_id` pointing at the old row so signatures stay verifiable. **The ICV/PIH chain continues across renewal** — it belongs to the EGS unit, not the certificate. |
| 12 | **Refund/cancellation after clearance** | Never modify or delete. Full cancellation = full credit note + `status = 'void'`. Partial = partial credit note. Both are themselves cleared/reported. §5.8 |
| 13 | **Refund on a marketplace-issued order** | We did not issue; we must not issue the credit note either. Record the refund for profit/VAT reporting, `issuer = 'marketplace'`, no submission. |
| 14 | **Order edited after invoicing** | Invoices are immutable. Changes require a credit note plus a new invoice. The order-edit UI warns when an invoice already exists. |
| 15 | **Buyer supplies a VAT number after a simplified invoice was issued** | Cannot convert. Issue a credit note for the simplified invoice, then a new standard invoice. Documented in help text — merchants will hit this. |
| 16 | **Duplicate webhook / re-sync** | `unique(organization_id, order_id, document_type)` + idempotent `firstOrCreate`. §7.3 |
| 17 | **Multi-currency order** | Convert to SAR for the tax total, populate `exchange_rate` and `TaxCurrencyCode`. **[UNVERIFIED]** rate source — see §5.7. |
| 18 | **Zero-value / 100%-discount order** | Still an invoice. VAT of 0 on a standard-rated line is valid; do not skip it. |
| 19 | **Rounding mismatch between lines and total** | Guard in §5.7 refuses issuance and raises an internal alert — this indicates a calculation bug, not merchant data. |
| 20 | **Org changes VAT number** | Requires a **new** CSR and CSID; the old EGS unit is revoked and a new one onboarded. Old invoices keep the old registration via `tax_registration_id`. |
| 21 | **Merchant deletes a store with issued invoices** | `store_id` is `nullOnDelete`; invoices survive. `orders` cascade-deletes today, so `invoices.order_id` is also `nullOnDelete`. **The invoice must denormalise everything it needs to render** — buyer, lines, totals — and never lazy-join to `orders` at render time. |
| 22 | **Sandbox invoice mistaken for real** | `environment` is stored per invoice's certificate; non-production invoices carry a loud watermark on the PDF and an amber badge in the UI, and are excluded from VAT reports. |
| 23 | **ZATCA rejects the CSR during onboarding** | Surface the raw ZATCA message plus a mapped hint (most commonly a malformed `organizationIdentifier` or a bad `serialNumber` format). Do not silently retry with mutated inputs. |
| 24 | **OTP expired** (1 hour, §3.14) | Detect the specific failure, tell the merchant plainly, and re-open the OTP step with a fresh countdown. |

---

## 12. Testing

### 12.1 Environments

| Environment | Use |
|---|---|
| **Sandbox** (`/developer-portal`) | Local + CI. Static behaviour, shared test credentials. No real certificates. |
| **Simulation** (`/simulation`) | Staging. Mirrors production semantics with non-live certificates. **All compliance rehearsal happens here.** |
| **Production** (`/core`) | Live only, behind a feature flag and an explicit per-org opt-in. |

`ZATCA_ENVIRONMENT` config drives base URL selection; **production is never reachable from a non-production `APP_ENV`** — assert this in a boot-time check and fail startup if violated.

### 12.2 Unit tests (PHPUnit)

> Run in Docker forcing sqlite: `-e DB_CONNECTION=sqlite` (per project memory).

| Area | Cases |
|---|---|
| `QrCodeBuilder` | Known-vector TLV → base64 matches ZATCA's published sample; Arabic seller name byte-length (not char-length); tag 9 present only for simplified; >255-byte field throws; total ≤700 chars |
| `InvoiceHasher` | BR-KSA-27 removals are exact; canonicalisation is stable across attribute ordering and whitespace; hash matches a known ZATCA sample invoice |
| `ChainAllocator` | ICV increments by exactly 1; PIH equals the previous hash; genesis PIH on first invoice; concurrent allocation under transactions produces no duplicates or gaps; `chain_broken_at` blocks allocation |
| `TaxCalculator` | Inclusive and exclusive decomposition; half-up at the .005 boundary; document-level VAT ≠ sum of rounded line VAT (the §3.8 case); multi-rate grouping; zero-rate and exempt categories |
| `InvoiceBuilder` | Subtype selection from buyer VAT presence; standard invoice missing buyer address is rejected; credit note inherits subtype and requires a reason |
| `CsrGenerator` | Subject DN contains all §3.15 OIDs; `organizationIdentifier` regex; `serialNumber` `1-|2-|3-` format; `TSCZ` map validation |
| `ChannelInvoicePolicy` | Each of the 7 platforms returns the §7.2 default; locked platforms cannot be overridden |
| `ZatcaClient` | Retry only on transport/5xx; no retry on 400/401; circuit breaker opens and closes; timeout does not regenerate the document |

### 12.3 Feature tests

- Full order → invoice → sign → submit (HTTP faked) → cleared, asserting persisted XML, QR, ICV, PIH, and a submission row.
- Rejection → credit note → corrected invoice, asserting new UUID/ICV and correct chaining.
- 24-hour deadline notification fires once, not repeatedly.
- Cross-tenant access to invoice, XML, PDF, and submissions all return 404.
- `viewer` role is refused issue/credit-note/settings, allowed reads.
- Editing or deleting an issued invoice returns 422.
- Duplicate `GenerateInvoiceJob` for one order creates exactly one invoice.

### 12.4 ZATCA compliance checks (onboarding)

Before a Production CSID is granted, the EGS must pass ZATCA's compliance checks via `/compliance/invoices` using the Compliance CSID. **[UNVERIFIED — exact count and composition]** — third-party guides commonly cite 6 documents (standard invoice + credit + debit, simplified invoice + credit + debit), and other sources cite up to 12 depending on the `TSCZ` functionality map declared in the CSR. **Confirm the exact required set from the current ZATCA compliance-checks documentation; the required set is a function of the declared `invoice_type_map`.** The implementation derives the document set from `invoice_type_map` rather than hard-coding a count.

Each document must be built by the **real production code path**, not a fixture — the point of the check is to validate our generator.

### 12.5 Fixtures

Store under `backend/tests/Fixtures/Zatca/`:
- ZATCA's published sample invoices (standard, simplified, and each note type) with their expected hashes and QR values — these are the ground truth for the hasher and QR builder.
- Recorded (scrubbed) sandbox responses for cleared, reported, rejected-with-errors, and warning cases.
- An Arabic-heavy invoice exercising multi-byte lengths.

### 12.6 Validation tooling

Use ZATCA's **SDK validator** and the **portal-based XML validator** on generated output in CI. Any spec-conformance regression should fail the build, not a merchant's invoice.

### 12.7 What must be manually verified before production

A checklist that a human signs off, because automated tests cannot prove these:

1. The curve (`secp256k1` vs `P-256`) — §3.5.
2. The genesis PIH value — §3.6.
3. The `name` attribute positions 3–7 — §3.3.
4. PIH behaviour after a rejected document — §5.3.
5. The C14N variant URI — §3.5.
6. Duplicate-submission semantics — §11 case 9.
7. Retention period and data residency — §3.16.

---

## 13. Rollout

### Milestone 0 — Foundations (no ZATCA calls)

Migrations, models, `InvoiceBuilder`, `TaxCalculator`, invoice list/detail UI, PDF rendering, tax-settings screens. Invoices generate as **non-fiscal documents**. Ships value immediately (merchants get proper VAT invoices) and de-risks everything downstream.

### Milestone 1 — Cryptography + sandbox

`CsrGenerator`, `InvoiceHasher`, `CryptographicStamper`, `QrCodeBuilder`, `ChainAllocator`, `ZatcaClient`. Sandbox Compliance CSID round-trip. **Exit criteria: the seven items in §12.7 items 1–3 and 5 are answered and documented in this file.**

### Milestone 2 — Simulation end-to-end

Full onboarding wizard, compliance checks, Production CSID in simulation, clearance and reporting, retry/sweeper jobs, error catalog. Internal dogfooding on a real Saudi test entity. **Exit criteria: §12.7 items 4 and 6 answered.**

### Milestone 3 — Design partners

3–5 real Saudi merchants, production CSIDs, live invoices, **daily manual reconciliation against the Fatoora portal for the first two weeks**. Feature-flagged per organization.

### Milestone 4 — General availability

KMS-backed key storage (§4.10 item 3) is a **hard prerequisite**. Self-serve onboarding, Arabic copy reviewed by a Saudi accountant, mobile surfaces, monitoring dashboards.

### Milestone 5 — UAE VAT

TRN capture and validation, AE invoice content rules, bilingual AE templates. Watch the PINT AE / ASP mandate; decide build-vs-partner (§16).

### Legal risk controls

1. **A Saudi tax advisor reviews the spec and sample output before Milestone 3.** Non-negotiable.
2. **Terms of service** must state clearly that the merchant remains the taxpayer and is responsible for the accuracy of invoice data; Hubby provides the generation and transmission mechanism. Have counsel draft this.
3. **Professional indemnity insurance** review before GA.
4. **An incident runbook** for: chain break, mass rejection, extended ZATCA outage, certificate compromise. Written and rehearsed before Milestone 3.
5. **A compliance dossier** recording every documented deviation (notably the software-key-module choice, §4.10 item 5) and every resolved **[UNVERIFIED]** item, with dates and sources.
6. **Never auto-issue on behalf of a merchant who has not completed onboarding.** No silent fiscal actions.
7. **Consider a ZATCA-certified solution provider partnership** — see §15.

---

## 14. Acceptance criteria

**Data & security**
- [ ] All seven migrations run clean on `migrate:fresh` and are reversible.
- [ ] Every fiscal table carries `organization_id` with an FK and an index.
- [ ] `private_key_encrypted` and `api_secret_encrypted` are never readable as plaintext in the DB, never serialized by any API resource, and never logged.
- [ ] No code path can hard-delete or update an invoice with `status != 'draft'`.
- [ ] Cross-tenant access to any invoice, XML, PDF, or submission returns 404.

**Correctness**
- [ ] Generated XML validates against ZATCA's schematron with zero errors for all six document types.
- [ ] QR TLV matches ZATCA's published sample vectors byte-for-byte, including an Arabic seller name.
- [ ] The QR base64 string is ≤700 characters.
- [ ] Tag 9 is present on simplified documents and absent on standard ones.
- [ ] Invoice hash matches ZATCA's expected value for the published sample invoice.
- [ ] ICV is gapless and strictly increasing per EGS unit under 50 concurrent generations.
- [ ] PIH of invoice *n* equals the invoice hash of invoice *n−1* for a 100-document chain.
- [ ] Document-level VAT is computed at document level, not as a sum of rounded line VAT — proven by a test where the two differ.
- [ ] All rounding is half-up to 2 decimals at document level.

**Flows**
- [ ] Standard invoices go to clearance; simplified to reporting; the routing is driven by buyer VAT presence.
- [ ] A standard invoice missing buyer street/city/country cannot be issued.
- [ ] Credit and debit notes require a reason and reference their parent.
- [ ] Cancellation is only achievable via credit note; no "delete" affordance exists in the UI.
- [ ] A cleared invoice stores ZATCA's returned stamped XML separately from our signed XML.

**Resilience**
- [ ] With ZATCA returning 503, invoices reach `failed` and are retried by the sweeper on schedule; every attempt is recorded in `invoice_submissions`.
- [ ] A simplified invoice unreported at +24h raises a high-severity notification.
- [ ] A timeout never causes document regeneration; the identical payload is re-sent.
- [ ] A deliberately corrupted chain is detected within 24h, blocks issuance, and alerts.
- [ ] Clock skew >5 minutes blocks issuance.

**Onboarding**
- [ ] A merchant completes sandbox onboarding end-to-end without engineering help.
- [ ] Expired OTP produces a clear, specific, recoverable error.
- [ ] Certificates expiring within 30 days trigger notifications.
- [ ] Switching to production requires the `owner` role and an explicit confirmation.

**Channels**
- [ ] Each of the 7 platforms has a seeded `invoice_issuer` default matching §7.2.
- [ ] An order from a `marketplace`-issuer store never produces a submitted invoice.
- [ ] Duplicate order webhooks produce exactly one invoice.
- [ ] Trendyol orders are never routed to ZATCA.

**UX / i18n**
- [ ] Every user-facing string exists in `en` and `ar`.
- [ ] Arabic invoice terminology reviewed and signed off by a Saudi accountant.
- [ ] The PDF renders correctly in both languages with a scannable QR.
- [ ] Sandbox/simulation invoices are visually unmistakable.
- [ ] SAR renders with the riyal glyph via `Money.tsx` throughout.

**Sign-off**
- [ ] All seven items in §12.7 are resolved, and every `[UNVERIFIED]` tag in this document is either resolved or explicitly accepted by the compliance owner.
- [ ] A Saudi tax advisor has reviewed sample output.

---

## 15. Effort estimate + dependencies

### Effort (2 backend, 1 frontend, plus part-time compliance/QA)

| Milestone | Backend | Frontend | Elapsed |
|---|---|---|---|
| M0 — Foundations | 3 wk | 2.5 wk | ~3 wk |
| M1 — Crypto + sandbox | 4 wk | 0.5 wk | ~4 wk |
| M2 — Simulation e2e | 4 wk | 3 wk | ~4 wk |
| M3 — Design partners | 2 wk | 1 wk | ~4 wk (calendar-bound) |
| M4 — GA (incl. KMS) | 2 wk | 1.5 wk | ~2 wk |
| M5 — UAE VAT | 2 wk | 1 wk | ~2 wk |
| **Total** | **~17 wk** | **~9.5 wk** | **~19 weeks (~4.5 months)** |

Add **+25%** contingency: this is a compliance feature against a partly-ambiguous external spec with an unresponsive feedback loop (sandbox quirks, support tickets). A realistic planning number is **5–6 months to GA**.

**The cryptography is not the long pole.** The long pole is the unknowns: exact CSR shape, curve, canonicalisation variant, and ZATCA's error semantics. Budget generously for M1–M2.

### Technical dependencies

| Dependency | Notes |
|---|---|
| PHP `ext-openssl` | ECDSA signing, CSR generation. **Verify `secp256k1` support in the Docker PHP 8.3 image** — not all builds enable it. |
| `robrichards/xmlseclibs` | XML-DSig / C14N primitives. Widely used; still requires custom XAdES B-B assembly. |
| `ext-dom`, `ext-libxml` | UBL construction and canonicalisation. |
| QR library (`endroid/qr-code` or `bacon/bacon-qr-code`) | Renders the base64 TLV payload. |
| PDF renderer (`dompdf` / `mpdf` / headless Chrome) | **Must support Arabic shaping and RTL.** `mpdf` handles Arabic well; `dompdf` does not. Test early — Arabic PDF rendering is a classic late-discovered blocker. |
| Object storage with versioning + object lock | Immutable archival (§4.10). |
| KMS / HSM | Production key wrapping. **Blocks GA.** |
| Redis | Queues, circuit-breaker state, locks. Already present. |
| NTP + drift monitoring | §11 case 8. |

### Non-technical dependencies

| Dependency | Why | Blocks |
|---|---|---|
| **KSA tax advisor** | Spec review, retention period, exchange-rate treatment, exemption codes | M3 |
| **Native Arabic accountant** for terminology review | Merchant credibility | M4 |
| **A real Saudi VAT-registered test entity** | Simulation and production onboarding need a real VAT number | M2 |
| **Fatoora portal access** for the test entity | OTPs are portal-only (§3.14) | M2 |
| **3–5 design-partner merchants** | Live validation | M3 |
| **Legal review of ToS liability wording** | Risk allocation | M3 |
| **Professional indemnity insurance review** | Risk | M4 |

### Should we use a certified solution provider / partner?

**Recommendation: build the core, but seriously evaluate a partner for M1–M2, and buy the validation tooling regardless.**

| | Build in-house | Partner / certified provider |
|---|---|---|
| Cost | ~5–6 months eng | Per-invoice or per-tenant fees, ongoing |
| Time to market | Slower | Materially faster |
| Moat | **Strong** — competitors must repeat the work | **Weaker** — competitors can buy the same partner |
| Risk | We own compliance bugs | Partner absorbs spec churn |
| Margin | Better at scale | Erodes with volume |
| Control | Full | Dependent on partner roadmap |

Given the strategy document calls this *the* regulatory moat, **the differentiating value is in owning it**. A partner that Linnworks could also sign is not a moat. But:

- **Strongly consider a paid engagement with a certified provider or an experienced ZATCA integration consultant for a 2–4 week advisory sprint during M1**, purely to resolve the §12.7 unknowns. This is likely to pay for itself several times over versus discovering the curve or canonicalisation variant through failed sandbox calls.
- **Do use ZATCA's own SDK and validator** in CI (§12.6) — that is free and authoritative.
- **Revisit build-vs-buy for UAE.** UAE's model *requires* an FTA-Accredited Service Provider, and Hubby will not be one. For UAE e-invoicing (2027), partnering with an ASP is almost certainly the right answer.

---

## 16. Open questions

### Blocking — must be answered before writing signing code

1. **Which elliptic curve does ZATCA require — `secp256k1` or `P-256`/`secp256r1`?** §3.5. *Owner: backend. Method: sandbox Compliance CSID call with each. Deadline: M1 week 1.*
2. **What exactly is the genesis PIH for the first document in a chain?** §3.6. *Method: ZATCA SDK sample + sandbox.*
3. **Which C14N variant** (inclusive/exclusive, with/without comments) does ZATCA's validator expect? §3.5.
4. **After a rejected document, does the next document's PIH chain off the rejected document or the last accepted one?** §5.3. **Highest consequence of any item here** — a wrong answer breaks every subsequent invoice. *Method: deliberately reject a document in simulation, then submit the next and observe.*
5. **What do `name` attribute positions 3–7 mean, and are non-zero values ever required for our flows?** §3.3.

### Blocking — before production

6. **Exact request/response schemas and headers** for all four APIs, from the current Swagger. §3.13.
7. **Duplicate submission semantics** — is re-sending an identical `uuid`+hash idempotent, or an error? §11 case 9.
8. **Data residency** — must KSA invoice data be stored inside Saudi Arabia? §3.16. *This can change our hosting architecture, so answer early.*
9. **Retention period** — the exact number of years, and whether audit logs share it. §3.16, §10.4. *Owner: tax advisor.*
10. **Exact composition of the compliance-check document set** as a function of `TSCZ`. §12.4.

### Important — before design-partner launch

11. **Do Salla and Zid issue ZATCA invoices themselves, and can a merchant disable it per store?** §7.2. *Method: partner/technical contact at each. This determines our double-invoicing default.*
12. **Does Amazon.sa VCS perform ZATCA Phase-2 clearance/reporting, or only produce a VAT invoice document?** §7.2. *If only the latter, the merchant still owes ZATCA submission and our default is wrong.*
13. **Confirm Noon's opt-in model directly with Noon partner support**, and whether opt-in status is exposed via the seller API (if so, we can detect it rather than ask). §7.2.
14. **Exchange-rate source and rounding** for non-SAR orders. §5.7.
15. **Is chain restart via a new EGS unit permitted** after an unrecoverable chain break? §11 case 6.
16. **VAT group handling** — one EGS unit per group member, or one per group? The CSR's `OU` carries a member TIN, implying per-member. §3.15.

### Strategic

17. **Do we pursue ZATCA solution-provider listing/certification?** It is a marketing asset and may be a procurement requirement for larger merchants.
18. **Do we partner with a UAE ASP for the 2027 PINT AE mandate, and when do we start?** §2, §15.
19. **Do we charge for ZATCA compliance separately** (a compliance add-on tier), or bundle it as the wedge that wins the KSA market? The strategy doc implies bundling to win the market; revisit once we know per-invoice infrastructure cost.
20. **Should the ICV/PIH chain be per EGS unit per store, or one per organization?** Per-organization is simpler and is the default here; per-store gives throughput headroom and blast-radius isolation on a chain break. Decide after M2 load testing.
