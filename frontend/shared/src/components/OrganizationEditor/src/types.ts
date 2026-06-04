export interface OrganizationEditorProps {
  visible: boolean;
  role: "user" | "admin";
  organizationId?: number | null;
  userId?: number;
  countryOptions: CountryCode[];
}

export interface CountryCode {
  value: string;
  label: string;
}

export interface OrganizationFormData {
  name: string;
  registration_number: string;
  country: string;
  state: string;
  city: string;
  address: string;
  postcode: string;
  phone: string;
}

export interface ContactFormData {
  last_name: string;
  first_name: string;
  identification_number: string;
  title: string;
  email: string;
  phone: string;
}
