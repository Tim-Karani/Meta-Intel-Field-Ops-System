/* Activity / campaign types from the product specification (free text on the API, constrained to a list in the UI). */
export const ACTIVITY_TYPES = ['merchandising', 'sampling', 'brand_activation', 'sales_drive', 'store_audit', 'market_survey', 'roadshow', 'event_activation', 'posm_deployment', 'stock_audit', 'osa_audit', 'competitor_audit', 'distribution', 'enumeration'];
export const CAMPAIGN_TYPES = ACTIVITY_TYPES;
export const QUESTION_TYPE_LABELS = {
  short_text: 'Short text', long_text: 'Long text', integer: 'Integer', decimal: 'Decimal', currency: 'Currency', yes_no: 'Yes / No', single_select: 'Single select',
  multi_select: 'Multi-select', dropdown: 'Dropdown', date_time: 'Date / time', rating: 'Rating', percentage: 'Percentage', product: 'Product', gps: 'GPS', photo: 'Photo', signature: 'Signature', barcode: 'Barcode / QR',
};
export const CONDITION_OPERATORS = { equals: 'equals', not_equals: 'does not equal', contains: 'contains', gt: 'is greater than', lt: 'is less than', answered: 'is answered' };
