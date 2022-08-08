<?php
$xml = new SimpleXMLElement('http://data.fcc.gov/api/license-view/basicSearch/getLicenses?searchValue='.$_GET["callsign"], 0, TRUE);
?>
<table>
  <thead>
    <tr>
      <th>Name</th>
      <th>Call Sign</th>
      <th>Type</th>
      <th>Status</th>
      <th>Expiration Date</th>
    </tr>
  </thead>
  <tbody>

<?php foreach ($xml->Licenses->License as $licenseElement) :?>
    <tr>
      <td><?php echo $licenseElement->licName; ?></td>
      <td><?php echo $licenseElement->callsign; ?></td>
      <td><?php echo $licenseElement->serviceDesc; ?></td>
      <td><?php echo $licenseElement->statusDesc; ?></td>
      <td><?php echo $licenseElement->expiredDate; ?></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
