<h1>Stellar Secret API</h1>
<p>This API handles database storage and external file storage for any uploaded files.</p>
<p>The API cannot see any messages and files since everything is encrypted on the client-side. Only those knowing the secret URL or the user-defined password can decrypt the data</p>

<h2>Data Model</h2><br>
* ID is stored hashed with sha512 into the DB.<br>
* Message is encrypted either by the ID generated on the UI-part or the user-custom password. The same goes with File (notice: file is not stored into the DB) but in our case Azure-blob-storage (might add multiple external-storage providers in future). Any access to Azure blob storage does not mean anything, since the files is encrypted with the user-defined password or the ID (which even with DB-access does not reveal anything as the ID is hashed)<br>
* Password is the user password for encrypting the data. It is stored hashed with sha512 - in future, we will remove the column, since we dont need it.<br>
* Expires_at is not encrypted, since we use this info to automatically delete a Secret in-case a link haven't been opened by the receiver. The deletion is done by Scheduler.<br>

<h3>TODO:</h3>

* If user sets a password for the Secret, use a combined encryption key of <strong>ID + Password</strong> instead of only Password.
* Make it possible to create accounts (anonymous?) where users can pay to upload bigger files.
* Possible to have a custom domain for secrets, such "secret.[UserDomain].com"
