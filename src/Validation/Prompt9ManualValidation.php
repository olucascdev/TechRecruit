<?php

declare(strict_types=1);

/*
Prompt 9 manual validation log (executed on 2026-03-17):

TEST 1 - Valid spreadsheet upload:
- File used: storage/imports/prompt9_contatos_teste.xlsx
- Expected: imported=5, error=1, duplicate=1
- Result: PASS (batch total_rows=7, imported_rows=5, error_rows=1, duplicates=1 in recruit_import_rows)

TEST 2 - Listing with filters:
- Route: /candidates?skill=VSAT
- Expected: only VSAT candidates
- Result: PASS (3 results: Alice VSAT, Carla VSAT, Eva VSAT; all with skill VSAT)

TEST 3 - Candidate detail:
- Route: /candidates/1 (imported candidate)
- Expected: full data visible + history with 1 entry
- Result: PASS (contacts/skills/address visible; status history count = 1)

TEST 4 - Status update:
- Route: POST /candidates/status (candidate_id=1, new_status=interested)
- Expected: status updated + history with 2 entries
- Result: PASS (candidate status=interested; status history count = 2)

Additional regression check from user report:
- File /home/lucasc/Downloads/contatos_teste.xlsx no longer fails import.
- Service result after fix: imported=5, errors=1, duplicates=1.
*/

