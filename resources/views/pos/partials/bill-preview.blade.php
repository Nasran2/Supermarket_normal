<svg viewBox="0 0 240 216" aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg">
@if($previewType === 'receipt')
    <path d="M62 9 H178 V199 L172 204 L166 199 L160 204 L154 199 L148 204 L142 199 L136 204 L130 199 L124 204 L118 199 L112 204 L106 199 L100 204 L94 199 L88 204 L82 199 L76 204 L70 199 L62 204 Z" fill="#dce5df" transform="translate(3 4)"/>
    <path d="M62 9 H178 V199 L172 204 L166 199 L160 204 L154 199 L148 204 L142 199 L136 204 L130 199 L124 204 L118 199 L112 204 L106 199 L100 204 L94 199 L88 204 L82 199 L76 204 L70 199 L62 204 Z" fill="white" stroke="#cbd8d0"/>
    <text x="120" y="30" text-anchor="middle" font-family="Arial,sans-serif" font-size="9" font-weight="700" fill="#334d40">YOUR STORE</text>
    <g stroke="#d3ddd7" stroke-width="3" stroke-linecap="round"><path d="M94 40 H146 M102 48 H138"/></g>
    <g stroke="#b8c8be" stroke-dasharray="2 2"><path d="M74 59 H166 M74 81 H166 M74 134 H166 M74 161 H166"/></g>
    <g fill="#8fa499" font-family="Arial,sans-serif" font-size="5"><text x="75" y="70">RECEIPT</text><text x="75" y="92">Item</text><text x="151" y="92">Qty</text><text x="75" y="145">TOTAL</text></g>
    <g stroke="#d3ddd7" stroke-width="4" stroke-linecap="round"><path d="M76 103 H121 M150 103 H162 M76 114 H132 M150 114 H162 M76 125 H113 M150 125 H162 M142 145 H162"/></g>
    <text x="120" y="177" text-anchor="middle" font-family="Arial,sans-serif" font-size="6" fill="#8fa499">THANK YOU</text>
    <g stroke="#607d6e" stroke-width="1.5"><path d="M95 183 V193 M99 183 V193 M104 183 V193 M106 183 V193 M112 183 V193 M117 183 V193 M119 183 V193 M123 183 V193 M128 183 V193 M133 183 V193 M136 183 V193 M141 183 V193 M145 183 V193"/></g>
@else
    <rect x="47" y="7" width="148" height="204" rx="2" fill="#dce5df"/>
    <rect x="44" y="4" width="148" height="204" rx="2" fill="white" stroke="#cbd8d0"/>
    <rect x="91" y="15" width="54" height="15" fill="white" stroke="#607d6e" stroke-width=".8"/>
    <text x="118" y="25" text-anchor="middle" font-family="Arial,sans-serif" font-size="7" font-weight="700" fill="#334d40">Tax Invoice</text>
    <g fill="none" stroke="#9cb0a3" stroke-width=".7"><path d="M54 39 H182 V51 H54 Z M54 57 H115 V94 H54 Z M121 57 H182 V94 H121 Z M54 100 H115 V112 H54 Z M121 100 H182 V112 H121 Z M54 118 H182 V130 H54 Z"/></g>
    <g fill="#607d6e" font-family="Arial,sans-serif" font-size="4"><text x="58" y="46">Invoice date</text><text x="125" y="46">Invoice no.</text><text x="58" y="65">Supplier / TIN</text><text x="125" y="65">Purchaser / TIN</text></g>
    <g stroke="#d3ddd7" stroke-width="2.5" stroke-linecap="round"><path d="M59 73 H101 M59 80 H106 M59 87 H91 M126 73 H167 M126 80 H172 M126 87 H158 M59 106 H100 M126 106 H167 M59 124 H151"/></g>
    <rect x="54" y="137" width="128" height="10" fill="#edf3ef"/>
    <g fill="none" stroke="#9cb0a3" stroke-width=".65"><path d="M54 137 H182 V182 H54 Z M54 147 H182 M54 158 H182 M54 169 H182 M73 137 V169 M129 137 V169 M146 137 V169 M160 137 V182 M54 176 H182 M54 189 H182 V199 H54 Z"/></g>
    <g fill="#607d6e" font-family="Arial,sans-serif" font-size="3.5"><text x="57" y="143">Ref.</text><text x="77" y="143">Description</text><text x="132" y="143">Qty</text><text x="163" y="143">Amount</text><text x="58" y="174">VAT</text><text x="58" y="180" font-weight="700">TOTAL</text></g>
    <g stroke="#d3ddd7" stroke-width="2.5" stroke-linecap="round"><path d="M78 153 H117 M78 164 H110 M58 194 H130"/></g>
@endif
</svg>
